<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    '/^http:\/\/localhost:\d+$/',
    '/^http:\/\/127\.0\.0\.1:\d+$/',
    '/^http:\/\/\[::1\]:\d+$/',
];

foreach ($allowedOrigins as $pattern) {
    if (
        $origin !== '' &&
        preg_match($pattern, $origin)
    ) {
        header(
            'Access-Control-Allow-Origin: ' . $origin
        );
        header('Vary: Origin');
        header(
            'Access-Control-Allow-Headers: Content-Type, Authorization'
        );
        header(
            'Access-Control-Allow-Methods: GET, POST, OPTIONS'
        );
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);

    echo json_encode([
        'success' => true,
    ]);

    exit;
}

require_once '../../config/database.php';

$conn->set_charset('utf8mb4');


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function respond(
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


function getAuthorizationHeader(): string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if ($authorization !== '') {
        return $authorization;
    }

    $authorization =
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if ($authorization !== '') {
        return $authorization;
    }

    if (function_exists('getallheaders')) {

        $headers = getallheaders();

        foreach ($headers as $key => $value) {

            if (
                strtolower((string) $key) ===
                'authorization'
            ) {
                return (string) $value;
            }
        }
    }

    return '';
}


function getBearerToken(): ?string
{
    $authorization =
        getAuthorizationHeader();

    if ($authorization === '') {
        return null;
    }

    if (
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            trim($authorization),
            $matches
        )
    ) {
        return null;
    }

    $token = trim($matches[1]);

    return $token !== ''
        ? $token
        : null;
}


function getJsonInput(): array
{
    $rawInput = file_get_contents('php://input');

    if (
        !is_string($rawInput) ||
        trim($rawInput) === ''
    ) {
        return [];
    }

    $data = json_decode(
        $rawInput,
        true
    );

    return is_array($data)
        ? $data
        : [];
}


function getPhotoUrl(
    string $fileName
): string {
    $host =
        $_SERVER['HTTP_HOST'] ??
        'localhost';

    $scheme =
        (
            (!empty($_SERVER['HTTPS']) &&
                $_SERVER['HTTPS'] !== 'off')
            ||
            (
                isset($_SERVER['SERVER_PORT']) &&
                (int) $_SERVER['SERVER_PORT'] === 443
            )
        )
        ? 'https'
        : 'http';

    return $scheme .
        '://' .
        $host .
        '/BKHS/public/uploads/profiles/' .
        rawurlencode($fileName);
}


function getInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'T';
    }

    $parts = preg_split(
        '/\s+/',
        $name
    );

    if (!$parts) {
        return strtoupper(
            substr($name, 0, 1)
        );
    }

    $initials = '';

    foreach (
        array_slice($parts, 0, 2)
        as $part
    ) {
        $initials .= strtoupper(
            substr($part, 0, 1)
        );
    }

    return $initials !== ''
        ? $initials
        : 'T';
}


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

if ($token === null) {
    respond(
        false,
        'Authentication token is required.',
        [],
        401
    );
}

$tokenHash = hash(
    'sha256',
    $token
);

$authSql = "
    SELECT
        u.id,
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
      AND LOWER(u.role) = 'teacher'
    LIMIT 1
";

$authStmt = $conn->prepare($authSql);

if (!$authStmt) {
    respond(
        false,
        'Could not prepare authentication query.',
        [],
        500
    );
}

$authStmt->bind_param(
    's',
    $tokenHash
);

$authStmt->execute();

$authResult =
    $authStmt->get_result();

$authenticatedUser =
    $authResult->fetch_assoc();

$authStmt->close();

if (!$authenticatedUser) {
    respond(
        false,
        'Invalid or expired authentication token.',
        [],
        401
    );
}

$teacherUserId =
    (int) $authenticatedUser['id'];


/*
|--------------------------------------------------------------------------
| Load Teacher Profile
|--------------------------------------------------------------------------
*/

function loadTeacherProfile(
    mysqli $conn,
    int $teacherUserId
): ?array {

    $sql = "
        SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            u.role,
            u.is_logged_in,
            u.last_login_at,
            u.created_at AS user_created_at,
            u.updated_at AS user_updated_at,

            t.id AS teacher_id,
            t.photo_path,
            t.gender,
            t.birth_eth_year,
            t.birth_eth_month,
            t.birth_eth_day,
            t.region,
            t.zone,
            t.woreda,
            t.marital_status,
            t.education_level,
            t.department,
            t.college_university_institution,
            t.created_at AS teacher_created_at,
            t.updated_at AS teacher_updated_at

        FROM users u

        LEFT JOIN teachers t
            ON t.user_id = u.id

        WHERE u.id = ?
          AND LOWER(u.role) = 'teacher'
          AND u.is_deleted = 0

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'i',
        $teacherUserId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $teacher =
        $result->fetch_assoc();

    $stmt->close();

    return $teacher ?: null;
}


/*
|--------------------------------------------------------------------------
| Format Profile Response
|--------------------------------------------------------------------------
*/

function profileResponse(
    array $teacher
): array {

    $photoPath =
        trim(
            (string) (
                $teacher['photo_path'] ?? ''
            )
        );

    $photoUrl = null;

    if ($photoPath !== '') {
        $photoUrl =
            getPhotoUrl(
                basename($photoPath)
            );
    }

    $birthYear =
        !empty($teacher['birth_eth_year'])
            ? (int) $teacher['birth_eth_year']
            : null;

    $birthMonth =
        !empty($teacher['birth_eth_month'])
            ? (int) $teacher['birth_eth_month']
            : null;

    $birthDay =
        !empty($teacher['birth_eth_day'])
            ? (int) $teacher['birth_eth_day']
            : null;

    return [

        'user_id' =>
            (int) $teacher['user_id'],

        'teacher_id' =>
            !empty($teacher['teacher_id'])
                ? (int) $teacher['teacher_id']
                : null,

        'full_name' =>
            (string) (
                $teacher['full_name'] ?? ''
            ),

        'email' =>
            (string) (
                $teacher['email'] ?? ''
            ),

        'phone' =>
            (string) (
                $teacher['phone'] ?? ''
            ),

        'role' =>
            (string) (
                $teacher['role'] ?? 'Teacher'
            ),

        'photo_path' =>
            $photoPath !== ''
                ? $photoPath
                : null,

        'photo_url' =>
            $photoUrl,

        'initials' =>
            getInitials(
                (string) (
                    $teacher['full_name'] ?? ''
                )
            ),

        'personal' => [

            'gender' =>
                $teacher['gender'] ?? null,

            'birth_eth_year' =>
                $birthYear,

            'birth_eth_month' =>
                $birthMonth,

            'birth_eth_day' =>
                $birthDay,

            'marital_status' =>
                $teacher['marital_status'] ?? null,
        ],

        'address' => [

            'region' =>
                $teacher['region'] ?? null,

            'zone' =>
                $teacher['zone'] ?? null,

            'woreda' =>
                $teacher['woreda'] ?? null,
        ],

        'professional' => [

            'education_level' =>
                $teacher['education_level'] ?? null,

            'department' =>
                $teacher['department'] ?? null,

            'college_university_institution' =>
                $teacher[
                    'college_university_institution'
                ] ?? null,
        ],

        'account' => [

            'is_logged_in' =>
                !empty(
                    $teacher['is_logged_in']
                ),

            'last_login_at' =>
                $teacher['last_login_at'] ?? null,

            'created_at' =>
                $teacher['user_created_at'] ?? null,

            'updated_at' =>
                $teacher['user_updated_at'] ?? null,
        ],
    ];
}


/*
|--------------------------------------------------------------------------
| GET PROFILE
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $teacher =
        loadTeacherProfile(
            $conn,
            $teacherUserId
        );

    if (!$teacher) {
        respond(
            false,
            'Teacher profile was not found.',
            [],
            404
        );
    }

    respond(
        true,
        'Teacher profile loaded successfully.',
        [
            'profile' =>
                profileResponse($teacher),
        ]
    );
}


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(
        false,
        'Only GET and POST requests are allowed.',
        [],
        405
    );
}


/*
|--------------------------------------------------------------------------
| Determine Action
|--------------------------------------------------------------------------
*/

$action =
    $_POST['action'] ??
    null;

if (
    !is_string($action) ||
    $action === ''
) {
    $jsonData =
        getJsonInput();

    $action =
        $jsonData['action'] ??
        null;
}


/*
|--------------------------------------------------------------------------
| UPDATE PHOTO
|--------------------------------------------------------------------------
*/

if ($action === 'update_photo') {

    if (
        !isset($_FILES['profile_photo']) ||
        !is_array($_FILES['profile_photo'])
    ) {
        respond(
            false,
            'Please select a photo.',
            [],
            400
        );
    }

    $file =
        $_FILES['profile_photo'];

    $uploadError =
        (int) (
            $file['error'] ??
            UPLOAD_ERR_NO_FILE
        );

    if (
        $uploadError !==
        UPLOAD_ERR_OK
    ) {

        $message = match (
            $uploadError
        ) {

            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE =>
                'The selected photo is too large.',

            UPLOAD_ERR_NO_FILE =>
                'Please select a photo.',

            default =>
                'The photo could not be uploaded.'
        };

        respond(
            false,
            $message,
            [],
            400
        );
    }

    $tmpName =
        $file['tmp_name'] ?? '';

    if (
        !is_string($tmpName) ||
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {
        respond(
            false,
            'Invalid uploaded file.',
            [],
            400
        );
    }


    /*
    | Maximum 5 MB
    */

    $maxFileSize =
        5 * 1024 * 1024;

    $fileSize =
        (int) (
            $file['size'] ?? 0
        );

    if ($fileSize <= 0) {
        respond(
            false,
            'The selected photo is empty.',
            [],
            400
        );
    }

    if ($fileSize > $maxFileSize) {
        respond(
            false,
            'Photo size must not exceed 5 MB.',
            [],
            400
        );
    }


    /*
    | Detect MIME type
    */

    $finfo =
        new finfo(
            FILEINFO_MIME_TYPE
        );

    $mimeType =
        $finfo->file($tmpName);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (
        !is_string($mimeType) ||
        !isset(
            $allowedTypes[$mimeType]
        )
    ) {
        respond(
            false,
            'Only JPG, PNG, and WebP images are allowed.',
            [],
            400
        );
    }


    /*
    | Upload directory
    */

    $uploadDirectory =
        dirname(__DIR__, 2) .
        DIRECTORY_SEPARATOR .
        'public' .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'profiles';

    if (
        !is_dir($uploadDirectory) &&
        !mkdir(
            $uploadDirectory,
            0755,
            true
        ) &&
        !is_dir($uploadDirectory)
    ) {
        respond(
            false,
            'The profile photo directory could not be created.',
            [],
            500
        );
    }


    /*
    | Generate secure filename
    */

    try {

        $randomName =
            bin2hex(
                random_bytes(16)
            );

    } catch (Throwable $exception) {

        respond(
            false,
            'Could not generate a secure photo name.',
            [],
            500
        );
    }

    $extension =
        $allowedTypes[$mimeType];

    $newFileName =
        'teacher_' .
        $teacherUserId .
        '_' .
        $randomName .
        '.' .
        $extension;

    $destination =
        $uploadDirectory .
        DIRECTORY_SEPARATOR .
        $newFileName;


    /*
    | Save new photo
    */

    if (
        !move_uploaded_file(
            $tmpName,
            $destination
        )
    ) {
        respond(
            false,
            'The profile photo could not be saved.',
            [],
            500
        );
    }


    /*
    | Load existing teacher record
    */

    $teacher =
        loadTeacherProfile(
            $conn,
            $teacherUserId
        );

    if (!$teacher) {

        @unlink($destination);

        respond(
            false,
            'Teacher profile was not found.',
            [],
            404
        );
    }

    $oldPhotoPath =
        trim(
            (string) (
                $teacher['photo_path'] ?? ''
            )
        );


    /*
    | Check whether teacher record exists
    */

    $teacherExists =
        !empty(
            $teacher['teacher_id']
        );


    if ($teacherExists) {

        $sql = "
            UPDATE teachers
            SET photo_path = ?
            WHERE user_id = ?
            LIMIT 1
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {

            @unlink($destination);

            respond(
                false,
                'Could not prepare the profile photo update.',
                [],
                500
            );
        }

        $stmt->bind_param(
            'si',
            $newFileName,
            $teacherUserId
        );

    } else {

        $sql = "
            INSERT INTO teachers (
                user_id,
                photo_path
            )
            VALUES (?, ?)
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {

            @unlink($destination);

            respond(
                false,
                'Could not create the teacher profile record.',
                [],
                500
            );
        }

        $stmt->bind_param(
            'is',
            $teacherUserId,
            $newFileName
        );
    }


    /*
    | Database update
    */

    if (!$stmt->execute()) {

        $stmt->close();

        @unlink($destination);

        respond(
            false,
            'Could not update the profile photo.',
            [],
            500
        );
    }

    $stmt->close();


    /*
    | Delete previous profile photo
    */

    if ($oldPhotoPath !== '') {

        $oldFileName =
            basename($oldPhotoPath);

        $oldFile =
            $uploadDirectory .
            DIRECTORY_SEPARATOR .
            $oldFileName;

        $realUploadDirectory =
            realpath(
                $uploadDirectory
            );

        $realOldFile =
            is_file($oldFile)
                ? realpath($oldFile)
                : false;

        if (
            $realUploadDirectory !== false &&
            $realOldFile !== false &&
            str_starts_with(
                $realOldFile,
                $realUploadDirectory .
                DIRECTORY_SEPARATOR
            )
        ) {
            @unlink($realOldFile);
        }
    }


    /*
    | Return updated profile
    */

    $updatedTeacher =
        loadTeacherProfile(
            $conn,
            $teacherUserId
        );

    respond(
        true,
        'Profile photo updated successfully.',
        [
            'profile' =>
                profileResponse(
                    $updatedTeacher ?? $teacher
                ),
        ]
    );
}


/*
|--------------------------------------------------------------------------
| UPDATE PASSWORD
|--------------------------------------------------------------------------
*/

if ($action === 'update_password') {

    $data =
        getJsonInput();

    $currentPassword =
        (string) (
            $_POST['current_password'] ??
            $data['current_password'] ??
            ''
        );

    $newPassword =
        (string) (
            $_POST['new_password'] ??
            $data['new_password'] ??
            ''
        );

    $confirmPassword =
        (string) (
            $_POST['confirm_password'] ??
            $data['confirm_password'] ??
            ''
        );


    /*
    | Required fields
    */

    if (
        $currentPassword === '' ||
        $newPassword === '' ||
        $confirmPassword === ''
    ) {
        respond(
            false,
            'Please fill in all password fields.',
            [],
            400
        );
    }


    /*
    | Minimum password length
    */

    if (
        strlen($newPassword) < 8
    ) {
        respond(
            false,
            'New password must be at least 8 characters long.',
            [],
            400
        );
    }


    /*
    | Confirm password
    */

    if (
        $newPassword !==
        $confirmPassword
    ) {
        respond(
            false,
            'New password and confirmation password do not match.',
            [],
            400
        );
    }


    /*
    | Load current password
    */

    $passwordSql = "
        SELECT password
        FROM users
        WHERE id = ?
          AND LOWER(role) = 'teacher'
          AND is_deleted = 0
        LIMIT 1
    ";

    $passwordStmt =
        $conn->prepare(
            $passwordSql
        );

    if (!$passwordStmt) {
        respond(
            false,
            'Could not verify your current password.',
            [],
            500
        );
    }

    $passwordStmt->bind_param(
        'i',
        $teacherUserId
    );

    $passwordStmt->execute();

    $passwordResult =
        $passwordStmt->get_result();

    $passwordRow =
        $passwordResult->fetch_assoc();

    $passwordStmt->close();

    $storedPassword =
        (string) (
            $passwordRow['password'] ?? ''
        );


    /*
    | Verify current password
    */

    if (
        $storedPassword === '' ||
        !password_verify(
            $currentPassword,
            $storedPassword
        )
    ) {
        respond(
            false,
            'Current password is incorrect.',
            [],
            400
        );
    }


    /*
    | New password must be different
    */

    if (
        password_verify(
            $newPassword,
            $storedPassword
        )
    ) {
        respond(
            false,
            'Your new password must be different from your current password.',
            [],
            400
        );
    }


    /*
    | Hash new password
    */

    $newPasswordHash =
        password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

    if (
        !is_string($newPasswordHash)
    ) {
        respond(
            false,
            'Could not securely create the new password.',
            [],
            500
        );
    }


    /*
    | Update password
    */

    $updatePasswordSql = "
        UPDATE users
        SET password = ?,
            updated_at = NOW()
        WHERE id = ?
          AND LOWER(role) = 'teacher'
          AND is_deleted = 0
        LIMIT 1
    ";

    $updatePasswordStmt =
        $conn->prepare(
            $updatePasswordSql
        );

    if (!$updatePasswordStmt) {
        respond(
            false,
            'Could not prepare the password update.',
            [],
            500
        );
    }

    $updatePasswordStmt->bind_param(
        'si',
        $newPasswordHash,
        $teacherUserId
    );

    if (
        !$updatePasswordStmt->execute()
    ) {

        $updatePasswordStmt->close();

        respond(
            false,
            'Could not update your password.',
            [],
            500
        );
    }

    $updatePasswordStmt->close();


    /*
    | Success
    */

    respond(
        true,
        'Password updated successfully.'
    );
}


/*
|--------------------------------------------------------------------------
| Unknown Action
|--------------------------------------------------------------------------
*/

respond(
    false,
    'Invalid profile action.',
    [],
    400
);
