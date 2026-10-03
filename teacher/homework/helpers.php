<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homework Helpers
|--------------------------------------------------------------------------
| Common functions used by the homework module.
|--------------------------------------------------------------------------
*/


/**
 * Escape output safely for HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES,
        'UTF-8'
    );
}


/**
 * Set a flash message for the next request.
 */
function setFlashMessage(
    string $type,
    string $message
): void {
    $_SESSION['homework_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}


/**
 * Get and remove the current flash message.
 */
function getFlashMessage(): ?array
{
    if (!isset($_SESSION['homework_flash'])) {
        return null;
    }

    $flash = $_SESSION['homework_flash'];

    unset($_SESSION['homework_flash']);

    if (
        !is_array($flash) ||
        !isset($flash['type']) ||
        !isset($flash['message'])
    ) {
        return null;
    }

    return [
        'type' => (string) $flash['type'],
        'message' => (string) $flash['message'],
    ];
}


/**
 * Generate or return the current CSRF token.
 */
function csrfToken(): string
{
    if (
        !isset($_SESSION['homework_csrf_token']) ||
        !is_string($_SESSION['homework_csrf_token']) ||
        $_SESSION['homework_csrf_token'] === ''
    ) {
        $_SESSION['homework_csrf_token'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['homework_csrf_token'];
}


/**
 * Validate the submitted CSRF token.
 */
function verifyCsrfToken(?string $token): bool
{
    if (
        $token === null ||
        $token === '' ||
        !isset($_SESSION['homework_csrf_token'])
    ) {
        return false;
    }

    return hash_equals(
        (string) $_SESSION['homework_csrf_token'],
        $token
    );
}


/**
 * Format an EthiopianCalendar::today() style array.
 */
function formatEthiopianDate(array $date): string
{
    $year = (int) ($date['year'] ?? 0);
    $month = (int) ($date['month'] ?? 0);
    $day = (int) ($date['day'] ?? 0);

    if (
        $year <= 0 ||
        $month <= 0 ||
        $day <= 0
    ) {
        return '';
    }

    $monthName = EthiopianCalendar::monthName(
        $month,
        'en'
    );

    if ($monthName === '') {
        return '';
    }

    return $monthName . ' ' . $day . ', ' . $year;
}


/**
 * Validate an Ethiopian date.
 *
 * Uses the same validation rules as EthiopianCalendar.
 */
function isValidEthiopianDate(
    int $year,
    int $month,
    int $day
): bool {
    if ($year <= 0) {
        return false;
    }

    if ($month < 1 || $month > 13) {
        return false;
    }

    try {
        $maximumDay = EthiopianCalendar::daysInMonth(
            $year,
            $month
        );
    } catch (Throwable) {
        return false;
    }

    return (
        $day >= 1 &&
        $day <= $maximumDay
    );
}


/**
 * Convert an Ethiopian date to a Gregorian date string.
 *
 * Returns:
 *     YYYY-MM-DD
 *
 * Returns null when the Ethiopian date is invalid
 * or conversion fails.
 */
function ethiopianDateToGregorian(
    int $year,
    int $month,
    int $day
): ?string {
    if (
        !isValidEthiopianDate(
            $year,
            $month,
            $day
        )
    ) {
        return null;
    }

    try {

        /*
         * EthiopianCalendar::toGregorian()
         * returns a Gregorian date in YYYY-MM-DD format.
         */
        $gregorian = EthiopianCalendar::toGregorian(
            $year,
            $month,
            $day
        );

        if (!is_string($gregorian)) {
            return null;
        }

        /*
         * Validate the returned Gregorian date.
         */
        $dateObject = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $gregorian
        );

        $errors = DateTimeImmutable::getLastErrors();

        if ($dateObject === false) {
            return null;
        }

        if (
            is_array($errors) &&
            (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )
        ) {
            return null;
        }

        if (
            $dateObject->format('Y-m-d') !==
            $gregorian
        ) {
            return null;
        }

        return $gregorian;

    } catch (Throwable) {
        return null;
    }
}


/**
 * Convert a Gregorian date to an Ethiopian date.
 *
 * Input:
 *     YYYY-MM-DD
 *
 * Returns:
 * [
 *     'year' => int,
 *     'month' => int,
 *     'day' => int
 * ]
 *
 * Returns null when conversion fails.
 */
function gregorianDateToEthiopian(
    string $date
): ?array {
    /*
     * Database dates should be exactly YYYY-MM-DD.
     */
    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $date,
        new DateTimeZone('Africa/Addis_Ababa')
    );

    $errors = DateTimeImmutable::getLastErrors();

    if ($dateObject === false) {
        return null;
    }

    if (
        is_array($errors) &&
        (
            $errors['warning_count'] > 0 ||
            $errors['error_count'] > 0
        )
    ) {
        return null;
    }

    if (
        $dateObject->format('Y-m-d') !==
        $date
    ) {
        return null;
    }

    try {

        $ethiopian = EthiopianCalendar::fromGregorian(
            $date
        );

    } catch (Throwable) {
        return null;
    }

    if (
        !is_array($ethiopian) ||
        !isset(
            $ethiopian['year'],
            $ethiopian['month'],
            $ethiopian['day']
        )
    ) {
        return null;
    }

    return [
        'year' => (int) $ethiopian['year'],
        'month' => (int) $ethiopian['month'],
        'day' => (int) $ethiopian['day'],
    ];
}


/**
 * Validate an uploaded homework file.
 *
 * Returns:
 * [
 *     'valid' => bool,
 *     'error' => string|null,
 *     'extension' => string|null,
 *     'mime_type' => string|null,
 *     'tmp_name' => string|null,
 *     'original_name' => string|null,
 *     'size' => int
 * ]
 */
function validateHomeworkUpload(
    array $file,
    int $maxSize = 10485760
): array {
    $result = [
        'valid' => false,
        'error' => null,
        'extension' => null,
        'mime_type' => null,
        'tmp_name' => null,
        'original_name' => null,
        'size' => 0,
    ];

    /*
    |--------------------------------------------------------------------------
    | Check required upload fields
    |--------------------------------------------------------------------------
    */

    if (
        !isset(
            $file['error'],
            $file['size'],
            $file['tmp_name'],
            $file['name']
        )
    ) {
        $result['error'] =
            'Invalid uploaded file.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | PHP upload error
    |--------------------------------------------------------------------------
    */

    $uploadError = (int) $file['error'];

    if (
        $uploadError ===
        UPLOAD_ERR_NO_FILE
    ) {
        $result['error'] =
            'No file was uploaded.';

        return $result;
    }

    if (
        $uploadError !==
        UPLOAD_ERR_OK
    ) {
        $result['error'] = match ($uploadError) {

            UPLOAD_ERR_INI_SIZE =>
                'The uploaded file is larger than the server upload limit.',

            UPLOAD_ERR_FORM_SIZE =>
                'The uploaded file is larger than the allowed upload size.',

            UPLOAD_ERR_PARTIAL =>
                'The file was only partially uploaded. Please try again.',

            UPLOAD_ERR_NO_TMP_DIR =>
                'The PHP temporary upload directory is missing.',

            UPLOAD_ERR_CANT_WRITE =>
                'PHP could not write the uploaded file.',

            UPLOAD_ERR_EXTENSION =>
                'A PHP extension stopped the file upload.',

            default =>
                'The file upload failed. Upload error code: ' .
                $uploadError,
        };

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Temporary upload path
    |--------------------------------------------------------------------------
    */

    $tmpName = (string) $file['tmp_name'];

    if ($tmpName === '') {
        $result['error'] =
            'The uploaded file has no temporary location.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm this is a real HTTP upload
    |--------------------------------------------------------------------------
    */

    if (!is_uploaded_file($tmpName)) {
        $result['error'] =
            'PHP does not recognize the file as a valid uploaded file.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Original filename
    |--------------------------------------------------------------------------
    */

    $originalName = trim(
        (string) $file['name']
    );

    if ($originalName === '') {
        $result['error'] =
            'The uploaded file has no filename.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | File size
    |--------------------------------------------------------------------------
    */

    $fileSize = (int) $file['size'];

    if ($fileSize <= 0) {
        $result['error'] =
            'The uploaded file is empty.';

        return $result;
    }

    if ($fileSize > $maxSize) {
        $result['error'] =
            'The uploaded file is too large. Maximum size is 10 MB.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | File extension
    |--------------------------------------------------------------------------
    */

    $extension = strtolower(
        pathinfo(
            $originalName,
            PATHINFO_EXTENSION
        )
    );

    $allowedExtensions = [
        'pdf',
        'doc',
        'docx',
        'ppt',
        'pptx',
        'xls',
        'xlsx',
        'jpg',
        'jpeg',
        'png',
        'zip',
    ];

    if (
        $extension === '' ||
        !in_array(
            $extension,
            $allowedExtensions,
            true
        )
    ) {
        $result['error'] =
            'This file type is not allowed. Allowed files: PDF, DOC, DOCX, PPT, PPTX, XLS, XLSX, JPG, JPEG, PNG and ZIP.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Detect MIME type
    |--------------------------------------------------------------------------
    */

    $mimeType = null;

    if (function_exists('finfo_open')) {

        $finfo = finfo_open(
            FILEINFO_MIME_TYPE
        );

        if ($finfo !== false) {

            $detectedMime = finfo_file(
                $finfo,
                $tmpName
            );

            finfo_close($finfo);

            if (is_string($detectedMime)) {
                $mimeType = $detectedMime;
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME types
    |--------------------------------------------------------------------------
    |
    | Office documents can legitimately be detected
    | as ZIP or application/octet-stream.
    |
    */

    $allowedMimeTypes = [

        'pdf' => [
            'application/pdf',
        ],

        'doc' => [
            'application/msword',
            'application/octet-stream',
        ],

        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],

        'ppt' => [
            'application/vnd.ms-powerpoint',
            'application/octet-stream',
        ],

        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/octet-stream',
        ],

        'xls' => [
            'application/vnd.ms-excel',
            'application/octet-stream',
        ],

        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],

        'jpg' => [
            'image/jpeg',
        ],

        'jpeg' => [
            'image/jpeg',
        ],

        'png' => [
            'image/png',
        ],

        'zip' => [
            'application/zip',
            'application/x-zip-compressed',
            'application/octet-stream',
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Validate MIME type
    |--------------------------------------------------------------------------
    */

    if (
        $mimeType !== null &&
        isset($allowedMimeTypes[$extension]) &&
        !in_array(
            $mimeType,
            $allowedMimeTypes[$extension],
            true
        )
    ) {
        $result['error'] =
            'The uploaded file content does not match its file type.';

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Successful validation
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    | Do not move or modify the temporary upload here.
    | update.php will call move_uploaded_file().
    |
    */

    $result['valid'] = true;

    $result['extension'] =
        $extension;

    $result['mime_type'] =
        $mimeType;

    $result['tmp_name'] =
        $tmpName;

    $result['original_name'] =
        $originalName;

    $result['size'] =
        $fileSize;

    return $result;
}


/**
 * Generate a safe random filename.
 */
function generateHomeworkFilename(
    string $extension
): string {
    return bin2hex(
        random_bytes(16)
    )
    . '_'
    . date('YmdHis')
    . '.'
    . strtolower($extension);
}


/**
 * Ensure a directory exists.
 */
function ensureDirectoryExists(
    string $directory
): bool {
    if (is_dir($directory)) {
        return true;
    }

    return mkdir(
        $directory,
        0775,
        true
    );
}


/**
 * Check whether a request is POST.
 */
function isPostRequest(): bool
{
    return (
        $_SERVER['REQUEST_METHOD'] ?? 'GET'
    ) === 'POST';
}


/**
 * Redirect to a location.
 */
function redirectTo(string $location): never
{
    header(
        'Location: ' . $location
    );

    exit;
}


/**
 * Return a cleaned integer request value.
 */
function requestInt(
    array $source,
    string $key,
    int $default = 0
): int {
    if (!isset($source[$key])) {
        return $default;
    }

    $value = filter_var(
        $source[$key],
        FILTER_VALIDATE_INT
    );

    if ($value === false) {
        return $default;
    }

    return (int) $value;
}


/**
 * Return a cleaned string request value.
 */
function requestString(
    array $source,
    string $key,
    string $default = ''
): string {
    if (!isset($source[$key])) {
        return $default;
    }

    return trim(
        (string) $source[$key]
    );
}


/**
 * Validate homework status.
 */
function isValidHomeworkStatus(
    string $status
): bool {
    return in_array(
        $status,
        [
            'Not Done',
            'Done',
        ],
        true
    );
}