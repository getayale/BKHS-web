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
        '/^http:\/\/(?:localhost|127\.0\.0\.1)(?::\d+)?$/',
        $origin
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function sendResponse(
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
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function formatFileSize(?int $bytes): string
{
    if ($bytes === null || $bytes <= 0) {
        return '';
    }

    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    if ($bytes < 1024 * 1024) {
        return number_format(
            $bytes / 1024,
            1
        ) . ' KB';
    }

    if ($bytes < 1024 * 1024 * 1024) {
        return number_format(
            $bytes / (1024 * 1024),
            1
        ) . ' MB';
    }

    return number_format(
        $bytes / (1024 * 1024 * 1024),
        1
    ) . ' GB';
}

function getFileIcon(string $fileName): string
{
    $extension = strtolower(
        pathinfo(
            $fileName,
            PATHINFO_EXTENSION
        )
    );

    return match ($extension) {
        'pdf' => '📄',
        'doc', 'docx' => '📝',
        'xls', 'xlsx' => '📊',
        'ppt', 'pptx' => '📑',
        'zip', 'rar' => '🗜️',
        default => '📎',
    };
}


/*
|--------------------------------------------------------------------------
| Request method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {

    sendResponse(
        false,
        'Method not allowed.',
        [],
        405
    );
}


/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

$authorizationHeader = '';

if (isset($_SERVER['HTTP_AUTHORIZATION'])) {

    $authorizationHeader =
        trim(
            (string) $_SERVER['HTTP_AUTHORIZATION']
        );

} elseif (function_exists('getallheaders')) {

    $headers = getallheaders();

    foreach ($headers as $name => $value) {

        if (
            strtolower((string) $name)
            === 'authorization'
        ) {

            $authorizationHeader =
                trim(
                    (string) $value
                );

            break;
        }
    }
}


/*
|--------------------------------------------------------------------------
| Validate Bearer token
|--------------------------------------------------------------------------
*/

if (
    $authorizationHeader === '' ||
    !preg_match(
        '/^Bearer\s+(.+)$/i',
        $authorizationHeader,
        $matches
    )
) {

    sendResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}

$rawToken =
    trim(
        (string) $matches[1]
    );

if ($rawToken === '') {

    sendResponse(
        false,
        'Unauthorized',
        [],
        401
    );
}


/*
|--------------------------------------------------------------------------
| Token hash
|--------------------------------------------------------------------------
*/

$tokenHash =
    hash(
        'sha256',
        $rawToken
    );


/*
|--------------------------------------------------------------------------
| Validate API token
|--------------------------------------------------------------------------
*/

try {

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

        throw new RuntimeException(
            'Failed to prepare token query.'
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

    if (!$tokenUser) {

        sendResponse(
            false,
            'Invalid or expired token.',
            [],
            401
        );
    }

    if (
        strtolower(
            (string) $tokenUser['role']
        ) !== 'parent'
    ) {

        sendResponse(
            false,
            'Access denied.',
            [],
            403
        );
    }

    $parentUserId =
        (int) $tokenUser['user_id'];


    /*
     * ----------------------------------------------------------
     * Selected student
     * ----------------------------------------------------------
     */

    $studentId = filter_input(
        INPUT_GET,
        'student_id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1
            ]
        ]
    );


    /*
     * ----------------------------------------------------------
     * Parent information
     * ----------------------------------------------------------
     */

    $parentStmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.phone,
            u.email,
            p.id AS parent_id,
            p.photo

        FROM users AS u

        INNER JOIN parents AS p
            ON p.user_id = u.id

        WHERE u.id = ?
          AND LOWER(u.role) = 'parent'
          AND u.is_deleted = 0

        LIMIT 1
    ");

    if (!$parentStmt) {

        throw new RuntimeException(
            'Failed to prepare parent query.'
        );
    }

    $parentStmt->bind_param(
        'i',
        $parentUserId
    );

    $parentStmt->execute();

    $parentResult =
        $parentStmt->get_result();

    $parent =
        $parentResult->fetch_assoc();

    $parentStmt->close();

    if (!$parent) {

        sendResponse(
            false,
            'Parent account was not found.',
            [],
            404
        );
    }


    /*
     * ----------------------------------------------------------
     * Active academic year
     * ----------------------------------------------------------
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

        throw new RuntimeException(
            'Failed to prepare academic year query.'
        );
    }

    $academicYearStmt->execute();

    $academicYearResult =
        $academicYearStmt->get_result();

    $activeAcademicYear =
        $academicYearResult->fetch_assoc();

    $academicYearStmt->close();

    if (!$activeAcademicYear) {

        sendResponse(
            false,
            'No active academic year was found.',
            [],
            404
        );
    }

    $academicYearId =
        (int) $activeAcademicYear['id'];

    $academicYearName =
        (string) $activeAcademicYear['name'];


    /*
     * ----------------------------------------------------------
     * If student_id is not supplied,
     * select the parent's first accessible child.
     * ----------------------------------------------------------
     */

    if (!$studentId) {

        $firstChildStmt = $conn->prepare("
            SELECT
                s.id AS student_id

            FROM parents AS p

            INNER JOIN student_parents AS sp
                ON sp.parent_id = p.id

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
              AND sp.is_account_access = 1
              AND s.is_deleted = 0
              AND u.is_deleted = 0
              AND LOWER(u.role) = 'student'

            ORDER BY
                g.grade_number ASC,
                sec.code ASC,
                s.full_name ASC

            LIMIT 1
        ");

        if (!$firstChildStmt) {

            throw new RuntimeException(
                'Failed to prepare child query.'
            );
        }

        $firstChildStmt->bind_param(
            'ii',
            $academicYearId,
            $parentUserId
        );

        $firstChildStmt->execute();

        $firstChildResult =
            $firstChildStmt->get_result();

        $firstChild =
            $firstChildResult->fetch_assoc();

        $firstChildStmt->close();

        if (!$firstChild) {

            sendResponse(
                false,
                'No child was found.',
                [],
                404
            );
        }

        $studentId =
            (int) $firstChild['student_id'];
    }


    /*
     * ----------------------------------------------------------
     * Verify selected child belongs to parent
     * ----------------------------------------------------------
     */

    $studentStmt = $conn->prepare("
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
          AND sp.student_id = ?
          AND sp.is_account_access = 1
          AND s.is_deleted = 0
          AND u.is_deleted = 0
          AND LOWER(u.role) = 'student'

        LIMIT 1
    ");

    if (!$studentStmt) {

        throw new RuntimeException(
            'Failed to prepare selected child query.'
        );
    }

    $studentStmt->bind_param(
        'iii',
        $academicYearId,
        $parentUserId,
        $studentId
    );

    $studentStmt->execute();

    $studentResult =
        $studentStmt->get_result();

    $student =
        $studentResult->fetch_assoc();

    $studentStmt->close();

    if (!$student) {

        sendResponse(
            false,
            'The selected child could not be found.',
            [],
            404
        );
    }

    $gradeNumber =
        (int) $student['grade_number'];


    /*
     * ----------------------------------------------------------
     * Automatically close expired announcements
     * ----------------------------------------------------------
     */

    $conn->query("
        UPDATE announcements
        SET status = 'Closed'
        WHERE status = 'Published'
          AND closed_at IS NOT NULL
          AND closed_at < CURDATE()
    ");


    /*
     * ----------------------------------------------------------
     * Load announcements
     * ----------------------------------------------------------
     *
     * Public announcements:
     *   Always visible.
     *
     * Parent announcements:
     *   Visible when grade is NULL or matches child's grade.
     *
     * Expired announcements:
     *   Excluded.
     * ----------------------------------------------------------
     */

    $announcementStmt = $conn->prepare("
        SELECT DISTINCT
            a.id,
            a.title,
            a.content,
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
                    aa.audience_type = 'Parent'

                    AND (
                        aa.grade IS NULL
                        OR aa.grade = ?
                    )
                )
              )

        ORDER BY
            a.published_at DESC,
            a.created_at DESC,
            a.id DESC
    ");

    if (!$announcementStmt) {

        throw new RuntimeException(
            'Failed to prepare announcement query.'
        );
    }

    $announcementStmt->bind_param(
        'i',
        $gradeNumber
    );

    $announcementStmt->execute();

    $announcementResult =
        $announcementStmt->get_result();

    $announcements = [];

    while (
        $row =
        $announcementResult->fetch_assoc()
    ) {

        $announcementId =
            (int) $row['id'];

        $announcements[$announcementId] = [
            'id' => $announcementId,
            'title' => (string) (
                $row['title'] ?? ''
            ),
            'content' => (string) (
                $row['content'] ?? ''
            ),
            'published_at' => $row['published_at'],
            'closed_at' => $row['closed_at'],
            'created_at' => $row['created_at'],
            'images' => [],
            'attachments' => []
        ];
    }

    $announcementStmt->close();


    /*
     * ----------------------------------------------------------
     * Load announcement media
     * ----------------------------------------------------------
     */

    if (!empty($announcements)) {

        $announcementIds =
            array_keys($announcements);

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($announcementIds),
                    '?'
                )
            );

        $types =
            str_repeat(
                'i',
                count($announcementIds)
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
                display_order ASC,
                id ASC
        ";

        $mediaStmt =
            $conn->prepare($mediaSql);

        if (!$mediaStmt) {

            throw new RuntimeException(
                'Failed to prepare media query.'
            );
        }

        $bindParams = [
            $types
        ];

        foreach (
            $announcementIds
            as &$announcementId
        ) {

            $bindParams[] =
                &$announcementId;
        }

        unset($announcementId);

        call_user_func_array(
            [
                $mediaStmt,
                'bind_param'
            ],
            $bindParams
        );

        $mediaStmt->execute();

        $mediaResult =
            $mediaStmt->get_result();

        while (
            $media =
            $mediaResult->fetch_assoc()
        ) {

            $announcementId =
                (int) $media['announcement_id'];

            if (
                !isset(
                    $announcements[
                        $announcementId
                    ]
                )
            ) {
                continue;
            }

            $filePath =
                (string) (
                    $media['file_path'] ?? ''
                );

            $originalName =
                (string) (
                    $media['original_name'] ?? ''
                );

            $mediaItem = [
                'id' => (int) $media['id'],
                'media_type' => (string) (
                    $media['media_type'] ?? ''
                ),
                'file_path' => $filePath,
                'original_name' => $originalName,
                'mime_type' => (string) (
                    $media['mime_type'] ?? ''
                ),
                'file_size' => isset(
                    $media['file_size']
                )
                    ? (int) $media['file_size']
                    : null,
                'file_size_formatted' =>
                    formatFileSize(
                        isset(
                            $media['file_size']
                        )
                            ? (int) $media['file_size']
                            : null
                    ),
                'display_order' => (int) (
                    $media['display_order'] ?? 0
                )
            ];

            if (
                $media['media_type']
                === 'Image'
            ) {

                $announcements[
                    $announcementId
                ]['images'][] =
                    $mediaItem;

            } elseif (
                $media['media_type']
                === 'Attachment'
            ) {

                $mediaItem['file_icon'] =
                    getFileIcon(
                        $originalName
                    );

                $announcements[
                    $announcementId
                ]['attachments'][] =
                    $mediaItem;
            }
        }

        $mediaStmt->close();
    }


    /*
     * ----------------------------------------------------------
     * Re-index announcements
     * ----------------------------------------------------------
     */

    $announcements =
        array_values(
            $announcements
        );


    /*
     * ----------------------------------------------------------
     * Response
     * ----------------------------------------------------------
     */

    sendResponse(
        true,
        'Announcements loaded successfully.',
        [
            'academic_year' => [
                'id' => $academicYearId,
                'name' => $academicYearName,
                'status' => 'Active'
            ],

            'student' => [
                'student_id' =>
                    (int) $student['student_id'],

                'student_code' =>
                    (string) $student['student_code'],

                'full_name' =>
                    (string) $student['full_name'],

                'relationship' =>
                    (string) (
                        $student['relationship']
                        ?? ''
                    ),

                'grade_number' =>
                    $gradeNumber,

                'grade_label' =>
                    'Grade ' . $gradeNumber,

                'section' =>
                    (string) $student['section']
            ],

            'announcements' =>
                $announcements,

            'announcements_count' =>
                count($announcements)
        ]
    );

} catch (Throwable $e) {

    error_log(
        'Parent announcements API error: ' .
        $e->getMessage()
    );

    sendResponse(
        false,
        'Unable to load announcements right now.',
        [],
        500
    );
}
