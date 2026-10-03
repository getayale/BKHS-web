<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$allowedOriginPattern = '/^https?:\/\/localhost(?::[0-9]+)?$/';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match($allowedOriginPattern, $origin)
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';
require_once '../../teacher/homework/helpers.php';
require_once '../../teacher/homework/data.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| JSON response
|--------------------------------------------------------------------------
*/

function apiResponse(
    bool $success,
    string $message = '',
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
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Authorization
|--------------------------------------------------------------------------
*/

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim(
            (string) $_SERVER['HTTP_AUTHORIZATION']
        );
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $name => $value) {
            if (
                strtolower((string) $name) ===
                'authorization'
            ) {
                return trim((string) $value);
            }
        }
    }

    return '';
}

function getBearerToken(): string
{
    $header = getAuthorizationHeader();

    if ($header === '') {
        apiResponse(
            false,
            'Authorization token is required.',
            [],
            401
        );
    }

    if (
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            $header,
            $matches
        )
    ) {
        apiResponse(
            false,
            'Invalid authorization format.',
            [],
            401
        );
    }

    return trim(
        (string) $matches[1]
    );
}

/*
|--------------------------------------------------------------------------
| Authenticate teacher
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

$tokenHash = hash(
    'sha256',
    $token
);

$stmt = $conn->prepare(
    "
    SELECT
        at.user_id,
        at.expires_at,
        u.full_name,
        u.email,
        u.phone,
        u.role
    FROM api_tokens at
    INNER JOIN users u
        ON u.id = at.user_id
    WHERE at.token_hash = ?
      AND at.expires_at > NOW()
      AND u.is_deleted = 0
    LIMIT 1
    "
);

$stmt->bind_param(
    's',
    $tokenHash
);

$stmt->execute();

$result = $stmt->get_result();

$teacher = $result->fetch_assoc();

$stmt->close();

if (!$teacher) {
    apiResponse(
        false,
        'Invalid or expired authorization token.',
        [],
        401
    );
}

if (
    strtolower((string) $teacher['role']) !==
    'teacher'
) {
    apiResponse(
        false,
        'Teacher access is required.',
        [],
        403
    );
}

$teacherUserId =
    (int) $teacher['user_id'];

/*
|--------------------------------------------------------------------------
| Request method
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiResponse(
        false,
        'Only POST requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| Read request
|--------------------------------------------------------------------------
*/

$contentType = strtolower(
    (string) (
        $_SERVER['CONTENT_TYPE'] ?? ''
    )
);

$requestData = $_POST;

if (
    str_contains(
        $contentType,
        'application/json'
    )
) {
    $rawBody = file_get_contents(
        'php://input'
    );

    if (
        $rawBody !== false &&
        trim($rawBody) !== ''
    ) {
        $jsonData = json_decode(
            $rawBody,
            true
        );

        if (
            json_last_error() !==
                JSON_ERROR_NONE ||
            !is_array($jsonData)
        ) {
            apiResponse(
                false,
                'Invalid JSON request body.',
                [],
                422
            );
        }

        $requestData = $jsonData;
    }
}

/*
|--------------------------------------------------------------------------
| Homework ID
|--------------------------------------------------------------------------
*/

$homeworkId = isset(
    $requestData['homework_id']
)
    ? (int) $requestData['homework_id']
    : 0;

if ($homeworkId <= 0) {
    apiResponse(
        false,
        'Invalid homework ID.',
        [],
        422
    );
}

/*
|--------------------------------------------------------------------------
| Active academic year
|--------------------------------------------------------------------------
*/

try {
    $academicYear =
        getActiveAcademicYear($conn);
} catch (Throwable) {
    apiResponse(
        false,
        'Unable to load the active academic year.',
        [],
        500
    );
}

if ($academicYear === null) {
    apiResponse(
        false,
        'There is no active academic year.',
        [],
        404
    );
}

$academicYearId =
    (int) $academicYear['id'];

$academicYearName =
    (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| Verify homework ownership
|--------------------------------------------------------------------------
*/

try {
    $homework = getTeacherHomework(
        $conn,
        $homeworkId,
        $teacherUserId,
        $academicYearName
    );
} catch (Throwable) {
    apiResponse(
        false,
        'Unable to load the homework.',
        [],
        500
    );
}

if ($homework === null) {
    apiResponse(
        false,
        'Homework was not found or you do not have permission to modify it.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Check teacher material
|--------------------------------------------------------------------------
*/

$teacherMaterialPath =
    !empty(
        $homework['teacher_material_path']
    )
        ? (string)
            $homework['teacher_material_path']
        : '';

if ($teacherMaterialPath === '') {
    apiResponse(
        true,
        'This homework has no teacher material to delete.',
        [
            'academic_year' => [
                'id' =>
                    $academicYearId,

                'name' =>
                    $academicYearName,

                'status' =>
                    (string)
                        $academicYear['status'],
            ],

            'homework' => [
                'id' =>
                    $homeworkId,

                'teacher_material' => [
                    'exists' => false,
                    'path' => null,
                    'original_name' => null,
                    'type' => null,
                    'size' => null,
                ],
            ],
        ]
    );
}

/*
|--------------------------------------------------------------------------
| Build physical file path
|--------------------------------------------------------------------------
*/

$projectRoot = dirname(
    __DIR__,
    2
);

$physicalMaterialPath =
    $projectRoot
    . DIRECTORY_SEPARATOR
    . str_replace(
        '/',
        DIRECTORY_SEPARATOR,
        ltrim(
            $teacherMaterialPath,
            '/\\'
        )
    );

/*
|--------------------------------------------------------------------------
| Remove material from database
|--------------------------------------------------------------------------
*/

try {
    $conn->begin_transaction();

    $removed = removeHomeworkMaterial(
        $conn,
        $homeworkId,
        $teacherUserId,
        $academicYearName
    );

    if (!$removed) {
        throw new RuntimeException(
            'Teacher material could not be removed from the database.'
        );
    }

    $conn->commit();
} catch (Throwable) {
    if ($conn->in_transaction) {
        $conn->rollback();
    }

    apiResponse(
        false,
        'Teacher material could not be removed.',
        [],
        500
    );
}

/*
|--------------------------------------------------------------------------
| Delete physical file
|--------------------------------------------------------------------------
*/

$physicalFileDeleted = true;

if (is_file($physicalMaterialPath)) {
    $physicalFileDeleted =
        @unlink(
            $physicalMaterialPath
        );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

$message =
    'Teacher material removed successfully.';

if (!$physicalFileDeleted) {
    $message .=
        ' The database record was removed, but the physical file could not be deleted.';
}

apiResponse(
    true,
    $message,
    [
        'academic_year' => [
            'id' =>
                $academicYearId,

            'name' =>
                $academicYearName,

            'status' =>
                (string)
                    $academicYear['status'],
        ],

        'homework' => [
            'id' =>
                $homeworkId,

            'grade' =>
                (int)
                    $homework['grade'],

            'section' =>
                (string)
                    $homework['section'],

            'grade_subject_id' =>
                (int)
                    $homework['grade_subject_id'],

            'subject_name' =>
                (string)
                    $homework['subject_name'],

            'teacher_material' => [
                'exists' => false,

                'path' => null,

                'original_name' => null,

                'type' => null,

                'size' => null,

                'physical_file_deleted' =>
                    $physicalFileDeleted,
            ],
        ],
    ]
);

