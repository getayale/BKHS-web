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
    header(
        'Access-Control-Allow-Origin: ' . $origin
    );

    header(
        'Access-Control-Allow-Headers: Content-Type, Authorization, Accept'
    );

    header(
        'Access-Control-Allow-Methods: GET, POST, OPTIONS'
    );

    header(
        'Access-Control-Allow-Credentials: true'
    );

    header(
        'Vary: Origin'
    );
}

/*
|--------------------------------------------------------------------------
| Handle CORS Preflight
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);

    echo json_encode(
        [
            'success' => true,
            'message' => 'CORS preflight successful.'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Get Authorization Header
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

    http_response_code(401);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Unauthorized'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$rawToken = trim(
    (string) $matches[1]
);

if ($rawToken === '') {

    http_response_code(401);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Unauthorized'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
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

if (!$tokenStmt) {

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Database error'
        ],
        JSON_UNESCAPED_UNICODE
    );

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
    strtolower(
        (string) $tokenUser['role']
    ) !== 'parent'
) {

    http_response_code(401);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Unauthorized'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$parentUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| Get Parent
|--------------------------------------------------------------------------
*/

$parentSql = "

    SELECT

        u.id AS user_id,

        u.full_name,

        u.phone,

        u.email,

        u.password,

        p.id AS parent_id,

        p.photo

    FROM users AS u

    INNER JOIN parents AS p

        ON p.user_id = u.id

    WHERE u.id = ?

      AND LOWER(u.role) = 'parent'

      AND u.is_deleted = 0

    LIMIT 1

";

$parentStmt = $conn->prepare(
    $parentSql
);

if (!$parentStmt) {

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Database error'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

$parentStmt->bind_param(
    'i',
    $parentUserId
);

$parentStmt->execute();

$parentResult = $parentStmt->get_result();

$parent = $parentResult->fetch_assoc();

$parentStmt->close();

if (!$parent) {

    http_response_code(404);

    echo json_encode(
        [
            'success' => false,
            'message' => 'Parent not found'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

$requestMethod = $_SERVER['REQUEST_METHOD'];

/*
|--------------------------------------------------------------------------
| Change Password
|--------------------------------------------------------------------------
*/

if (
    $requestMethod === 'POST' &&
    ($_POST['action'] ?? '') === 'change_password'
) {

    $currentPassword =
        (string) ($_POST['current_password'] ?? '');

    $newPassword =
        (string) ($_POST['new_password'] ?? '');

    $confirmPassword =
        (string) ($_POST['confirm_password'] ?? '');

    if (
        trim($currentPassword) === '' ||
        trim($newPassword) === '' ||
        trim($confirmPassword) === ''
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Please fill in all password fields.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if ($newPassword !== $confirmPassword) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' =>
                    'New password and confirmation password do not match.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if (strlen($newPassword) < 6) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' =>
                    'New password must be at least 6 characters.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if (
        !password_verify(
            $currentPassword,
            (string) $parent['password']
        )
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Current password is incorrect.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $newPasswordHash = password_hash(
        $newPassword,
        PASSWORD_DEFAULT
    );

    if ($newPasswordHash === false) {

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Failed to secure the new password.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $updatePasswordSql = "

        UPDATE users

        SET password = ?

        WHERE id = ?

          AND LOWER(role) = 'parent'

          AND is_deleted = 0

        LIMIT 1

    ";

    $updatePasswordStmt = $conn->prepare(
        $updatePasswordSql
    );

    if (!$updatePasswordStmt) {

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Database error'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $updatePasswordStmt->bind_param(
        'si',
        $newPasswordHash,
        $parentUserId
    );

    if (
        !$updatePasswordStmt->execute()
    ) {

        $updatePasswordStmt->close();

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Failed to change password.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $updatePasswordStmt->close();

    echo json_encode(
        [
            'success' => true,
            'message' =>
                'Password changed successfully.'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Change Profile Photo
|--------------------------------------------------------------------------
*/

if (
    $requestMethod === 'POST' &&
    ($_POST['action'] ?? '') === 'change_photo'
) {

    if (
        !isset($_FILES['photo']) ||
        !is_array($_FILES['photo'])
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Please select a photo.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $photoFile = $_FILES['photo'];

    if (
        !isset($photoFile['error']) ||
        $photoFile['error'] !== UPLOAD_ERR_OK
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Photo upload failed.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    if (
        !isset($photoFile['tmp_name']) ||
        !is_uploaded_file(
            $photoFile['tmp_name']
        )
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Invalid uploaded photo.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $maxFileSize = 5 * 1024 * 1024;

    if (
        !isset($photoFile['size']) ||
        (int) $photoFile['size'] > $maxFileSize
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' =>
                    'Photo size must not exceed 5 MB.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $imageInfo = @getimagesize(
        $photoFile['tmp_name']
    );

    if ($imageInfo === false) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' => 'The uploaded file is not a valid image.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $mimeType =
        strtolower(
            (string) ($imageInfo['mime'] ?? '')
        );

    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (
        !isset(
            $allowedMimeTypes[$mimeType]
        )
    ) {

        http_response_code(400);

        echo json_encode(
            [
                'success' => false,
                'message' =>
                    'Only JPG, PNG, and WEBP images are allowed.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $extension =
        $allowedMimeTypes[$mimeType];

    /*
    |--------------------------------------------------------------------------
    | Upload Directory
    |--------------------------------------------------------------------------
    */

    $uploadDirectory =
        __DIR__ . '/../../uploads/parents';

    if (
        !is_dir($uploadDirectory)
    ) {

        if (
            !mkdir(
                $uploadDirectory,
                0755,
                true
            )
        ) {

            http_response_code(500);

            echo json_encode(
                [
                    'success' => false,
                    'message' =>
                        'Failed to create photo upload directory.'
                ],
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Secure Filename
    |--------------------------------------------------------------------------
    */

    $parentId =
        (int) $parent['parent_id'];

    $filename =
        'parent_' .
        $parentId .
        '_' .
        bin2hex(
            random_bytes(16)
        ) .
        '.' .
        $extension;

    $destination =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $filename;

    if (
        !move_uploaded_file(
            $photoFile['tmp_name'],
            $destination
        )
    ) {

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' =>
                    'Failed to save the uploaded photo.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Database Photo Path
    |--------------------------------------------------------------------------
    */

    $photoPath =
        'uploads/parents/' .
        $filename;

    $updatePhotoSql = "

        UPDATE parents

        SET photo = ?

        WHERE id = ?

        LIMIT 1

    ";

    $updatePhotoStmt = $conn->prepare(
        $updatePhotoSql
    );

    if (!$updatePhotoStmt) {

        @unlink($destination);

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Database error'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $updatePhotoStmt->bind_param(
        'si',
        $photoPath,
        $parentId
    );

    if (
        !$updatePhotoStmt->execute()
    ) {

        $updatePhotoStmt->close();

        @unlink($destination);

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' =>
                    'Failed to update profile photo.'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $updatePhotoStmt->close();

    /*
    |--------------------------------------------------------------------------
    | Delete Old Photo
    |--------------------------------------------------------------------------
    */

    $oldPhoto =
        (string) ($parent['photo'] ?? '');

    if ($oldPhoto !== '') {

        $oldFilename =
            basename($oldPhoto);

        $oldPhotoPath =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $oldFilename;

        $realUploadDirectory =
            realpath(
                $uploadDirectory
            );

        $realOldPhoto =
            realpath(
                $oldPhotoPath
            );

        if (
            $realUploadDirectory !== false &&
            $realOldPhoto !== false &&
            str_starts_with(
                $realOldPhoto,
                $realUploadDirectory .
                DIRECTORY_SEPARATOR
            )
        ) {

            @unlink($realOldPhoto);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Photo URL
    |--------------------------------------------------------------------------
    */

    $scheme =
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        )
            ? 'https'
            : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';

    $photoUrl =
        $scheme .
        '://' .
        $host .
        '/BKHS/' .
        $photoPath;

    echo json_encode(
        [
            'success' => true,
            'message' =>
                'Profile photo updated successfully.',
            'data' => [
                'photo' =>
                    $photoPath,
                'photo_url' =>
                    $photoUrl
            ]
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Only GET Is Allowed After POST Actions
|--------------------------------------------------------------------------
*/

if ($requestMethod !== 'GET') {

    http_response_code(405);

    echo json_encode(
        [
            'success' => false,
            'message' =>
                'Invalid request.'
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Get Active Academic Year
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

    $academicYear =
        $academicYearResult->fetch_assoc();
}

/*
|--------------------------------------------------------------------------
| Get Parent's Children
|--------------------------------------------------------------------------
*/

$children = [];

if ($academicYear) {

    $childrenSql = "

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

        WHERE p.id = ?

          AND sp.is_account_access = 1

          AND s.is_deleted = 0

          AND u.is_deleted = 0

          AND LOWER(u.role) = 'student'

        ORDER BY

            g.grade_number ASC,

            sec.code ASC,

            s.full_name ASC

    ";

    $childrenStmt = $conn->prepare(
        $childrenSql
    );

    if (!$childrenStmt) {

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Database error'
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }

    $academicYearId =
        (int) $academicYear['id'];

    $parentId =
        (int) $parent['parent_id'];

    $childrenStmt->bind_param(
        'ii',
        $academicYearId,
        $parentId
    );

    $childrenStmt->execute();

    $childrenResult =
        $childrenStmt->get_result();

    while (
        $row =
        $childrenResult->fetch_assoc()
    ) {

        $gradeNumber =
            (int) $row['grade_number'];

        $children[] = [

            'student_id' =>
                (int) $row['student_id'],

            'student_code' =>
                (string) $row['student_code'],

            'full_name' =>
                (string) $row['full_name'],

            'relationship' =>
                (string) (
                    $row['relationship'] ?? ''
                ),

            'registration_id' =>
                (int) $row['registration_id'],

            'grade_number' =>
                $gradeNumber,

            'grade_label' =>
                'Grade ' . $gradeNumber,

            'section' =>
                (string) $row['section']

        ];
    }

    $childrenStmt->close();
}

/*
|--------------------------------------------------------------------------
| Parent Photo
|--------------------------------------------------------------------------
*/

$photo =
    (string) ($parent['photo'] ?? '');

$photoUrl = null;

if ($photo !== '') {

    $scheme =
        (
            !empty($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        )
            ? 'https'
            : 'http';

    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';

    $photoUrl =
        $scheme .
        '://' .
        $host .
        '/BKHS/' .
        ltrim(
            $photo,
            '/'
        );
}

/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

echo json_encode(
    [
        'success' => true,

        'message' =>
            'Parent profile loaded successfully.',

        'data' => [

            'parent' => [

                'user_id' =>
                    (int) $parent['user_id'],

                'parent_id' =>
                    (int) $parent['parent_id'],

                'full_name' =>
                    (string) $parent['full_name'],

                'phone' =>
                    (string) (
                        $parent['phone'] ?? ''
                    ),

                'email' =>
                    (string) (
                        $parent['email'] ?? ''
                    ),

                'role' =>
                    'parent',

                'photo' =>
                    $photo,

                'photo_url' =>
                    $photoUrl

            ],

            'academic_year' =>
                $academicYear
                    ? [

                        'id' =>
                            (int) $academicYear['id'],

                        'name' =>
                            (string) $academicYear['name'],

                        'status' =>
                            (string) $academicYear['status']

                    ]
                    : null,

            'children' =>
                $children,

            'children_count' =>
                count($children)

        ]
    ],
    JSON_UNESCAPED_UNICODE
);
