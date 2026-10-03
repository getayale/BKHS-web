<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

function requireStudent(): void
{
    if (
        !isset($_SESSION['logged_in']) ||
        $_SESSION['logged_in'] !== true ||
        !isset($_SESSION['user_id']) ||
        !isset($_SESSION['role']) ||
        strtolower((string) $_SESSION['role']) !== 'student'
    ) {
        header('Location: ../../auth/login.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Output escaping
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool
{
    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

function setFlash(string $type, string $message): void
{
    $_SESSION['homework_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['homework_flash'])) {
        return null;
    }

    $flash = $_SESSION['homework_flash'];

    unset($_SESSION['homework_flash']);

    return $flash;
}

/*
|--------------------------------------------------------------------------
| Ethiopian date
|--------------------------------------------------------------------------
*/

function formatEthiopianDate(?string $gregorianDate): string
{
    if (!$gregorianDate) {
        return '-';
    }

    try {
        $date = substr($gregorianDate, 0, 10);

        $eth = EthiopianCalendar::fromGregorian($date);

        return sprintf(
            '%s %s %d',
            $eth['day'],
            $eth['month_name'],
            $eth['year']
        );
    } catch (Throwable) {
        return $gregorianDate;
    }
}

/*
|--------------------------------------------------------------------------
| Allowed student submission files
|--------------------------------------------------------------------------
*/

function allowedSubmissionExtensions(): array
{
    return [
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
}

function allowedSubmissionMimeTypes(): array
{
    return [
        'pdf' => [
            'application/pdf',
        ],

        'doc' => [
            'application/msword',
        ],

        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],

        'ppt' => [
            'application/vnd.ms-powerpoint',
        ],

        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
        ],

        'xls' => [
            'application/vnd.ms-excel',
        ],

        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
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
        ],
    ];
}

/*
|--------------------------------------------------------------------------
| Validate uploaded file
|--------------------------------------------------------------------------
*/

function validateSubmissionUpload(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return [
            'valid' => false,
            'error' => 'Invalid upload.',
        ];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $message = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file is too large.',

            UPLOAD_ERR_PARTIAL => 'The file upload was incomplete.',

            UPLOAD_ERR_NO_FILE => 'Please select a file.',

            default => 'The file could not be uploaded.',
        };

        return [
            'valid' => false,
            'error' => $message,
        ];
    }

    $maxSize = 10 * 1024 * 1024;

    if ((int) $file['size'] > $maxSize) {
        return [
            'valid' => false,
            'error' => 'File size must not exceed 10 MB.',
        ];
    }

    $originalName = basename((string) $file['name']);

    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );

    if (!in_array($extension, allowedSubmissionExtensions(), true)) {
        return [
            'valid' => false,
            'error' => 'This file type is not allowed.',
        ];
    }

    $tmpPath = (string) $file['tmp_name'];

    if (!is_uploaded_file($tmpPath)) {
        return [
            'valid' => false,
            'error' => 'Invalid uploaded file.',
        ];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mime = $finfo->file($tmpPath);

    $allowedMimes = allowedSubmissionMimeTypes();

    if (
        !isset($allowedMimes[$extension]) ||
        !in_array($mime, $allowedMimes[$extension], true)
    ) {
        return [
            'valid' => false,
            'error' => 'The uploaded file content does not match its file type.',
        ];
    }

    return [
        'valid' => true,
        'extension' => $extension,
        'mime' => $mime,
        'tmp_name' => $tmpPath,
        'original_name' => $originalName,
        'size' => (int) $file['size'],
    ];
}

/*
|--------------------------------------------------------------------------
| Submission upload directory
|--------------------------------------------------------------------------
*/

function submissionUploadDirectory(): string
{
    return dirname(__DIR__, 2) . '/uploads/homework/submissions';
}

function ensureSubmissionUploadDirectory(): void
{
    $directory = submissionUploadDirectory();

    if (!is_dir($directory)) {
        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(
                'Could not create the homework submission directory.'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Generate safe stored filename
|--------------------------------------------------------------------------
*/

function generateSubmissionFilename(
    int $studentId,
    int $homeworkId,
    string $extension
): string {
    return sprintf(
        'student_%d_homework_%d_%s.%s',
        $studentId,
        $homeworkId,
        bin2hex(random_bytes(8)),
        $extension
    );
}

/*
|--------------------------------------------------------------------------
| Convert physical path to project-relative path
|--------------------------------------------------------------------------
*/

function submissionRelativePath(string $filename): string
{
    return 'uploads/homework/submissions/' . $filename;
}

/*
|--------------------------------------------------------------------------
| Delete submission file safely
|--------------------------------------------------------------------------
*/

function deleteSubmissionFile(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $relativePath = ltrim($relativePath, '/\\');

    $prefix = 'uploads/homework/submissions/';

    if (!str_starts_with($relativePath, $prefix)) {
        return;
    }

    $fullPath = dirname(__DIR__, 2) . '/' . $relativePath;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
|--------------------------------------------------------------------------
| Redirect
|--------------------------------------------------------------------------
*/

function redirectToHomework(): never
{
    header('Location: ../homework.php');
    exit;
}