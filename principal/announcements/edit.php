<?php

declare(strict_types=1);

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'principal'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/*
|--------------------------------------------------------------------------
| Helper functions
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

function redirectToView(int $id): never
{
    header('Location: view.php?id=' . $id);
    exit;
}

/**
 * Convert Gregorian date to Ethiopian formatted date.
 */
function formatEthiopianDate(?string $dateTime): string
{
    if (empty($dateTime)) {
        return '—';
    }

    try {
        $date = substr($dateTime, 0, 10);

        $ethiopian =
            EthiopianCalendar::fromGregorian($date);

        return $ethiopian['formatted'] ?? $date;

    } catch (Throwable $exception) {
        return substr($dateTime, 0, 10);
    }
}

/**
 * Convert Gregorian date to Ethiopian YYYY-MM-DD.
 */
function gregorianToEthiopianInput(
    ?string $dateTime
): string {

    if (empty($dateTime)) {
        return '';
    }

    try {

        $date =
            substr($dateTime, 0, 10);

        $ethiopian =
            EthiopianCalendar::fromGregorian($date);

        return sprintf(
            '%04d-%02d-%02d',
            (int) $ethiopian['year'],
            (int) $ethiopian['month'],
            (int) $ethiopian['day']
        );

    } catch (Throwable $exception) {
        return '';
    }
}

/**
 * Validate Ethiopian date and return its parts.
 */
function parseEthiopianDate(
    string $value
): ?array {

    $value = trim($value);

    if (
        !preg_match(
            '/^(\d{4})-(\d{1,2})-(\d{1,2})$/',
            $value,
            $matches
        )
    ) {
        return null;
    }

    $year =
        (int) $matches[1];

    $month =
        (int) $matches[2];

    $day =
        (int) $matches[3];

    if ($year < 1) {
        return null;
    }

    if ($month < 1 || $month > 13) {
        return null;
    }

    try {

        /*
         * Let the user's EthiopianCalendar class
         * perform the authoritative validation.
         */
        EthiopianCalendar::ethiopianToGregorian(
            $year,
            $month,
            $day
        );

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ];

    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * Convert Ethiopian YYYY-MM-DD to Gregorian YYYY-MM-DD.
 *
 * IMPORTANT:
 * This uses the exact static method from the
 * user's EthiopianCalendar implementation.
 */
function ethiopianToGregorianDate(
    string $value
): ?string {

    $parts =
        parseEthiopianDate($value);

    if ($parts === null) {
        return null;
    }

    try {

        return EthiopianCalendar::toGregorian(
            $parts['year'],
            $parts['month'],
            $parts['day']
        );

    } catch (Throwable $exception) {
        return null;
    }
}

/**
 * Convert stored media path to browser URL.
 */
function mediaUrl(string $path): string
{
    return '../../' . ltrim($path, '/');
}

/**
 * Check whether media is an image.
 */
function isImageMime(?string $mime): bool
{
    return in_array(
        strtolower((string) $mime),
        [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
        ],
        true
    );
}

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['announcement_csrf']) ||
    !is_string($_SESSION['announcement_csrf']) ||
    strlen($_SESSION['announcement_csrf']) !== 64
) {
    $_SESSION['announcement_csrf'] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    $_SESSION['announcement_csrf'];

/*
|--------------------------------------------------------------------------
| Announcement ID
|--------------------------------------------------------------------------
*/

$announcementId =
    filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

if (!$announcementId) {

    header('Location: ../announcements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Principal information
|--------------------------------------------------------------------------
*/

$principal = [
    'id' => 0,
    'full_name' => 'Principal',
    'email' => '',
    'phone' => '',
    'photo' => '',
];

$principalUserId =
    (int) $_SESSION['user_id'];

$principalStmt =
    $conn->prepare(
        "SELECT
            u.id,
            u.full_name,
            u.email,
            u.phone,
            p.photo
         FROM users u
         LEFT JOIN principals p
            ON p.user_id = u.id
         WHERE u.id = ?
           AND LOWER(u.role) = 'principal'
           AND u.is_deleted = 0
         LIMIT 1"
    );

$principalStmt->bind_param(
    'i',
    $principalUserId
);

$principalStmt->execute();

$principalResult =
    $principalStmt->get_result();

if ($row = $principalResult->fetch_assoc()) {
    $principal = $row;
}

$principalStmt->close();

/*
|--------------------------------------------------------------------------
| Principal photo
|--------------------------------------------------------------------------
*/

$principalPhoto =
    $principal['photo'] ?? '';

$principalPhotoUrl = '';

if (!empty($principalPhoto)) {

    $principalPhotoUrl =
        '../../' .
        ltrim($principalPhoto, '/');
}

/*
|--------------------------------------------------------------------------
| Automatically close expired announcements
|--------------------------------------------------------------------------
*/

$expireStmt =
    $conn->prepare(
        "UPDATE announcements
         SET status = 'Closed'
         WHERE status = 'Published'
           AND closed_at IS NOT NULL
           AND closed_at < NOW()"
    );

$expireStmt->execute();
$expireStmt->close();

/*
|--------------------------------------------------------------------------
| Load announcement
|--------------------------------------------------------------------------
*/

$announcementStmt =
    $conn->prepare(
        "SELECT
            a.id,
            a.title,
            a.content,
            a.status,
            a.created_by,
            a.published_at,
            a.closed_at,
            a.created_at,
            a.updated_at,
            u.full_name AS created_by_name
         FROM announcements a
         LEFT JOIN users u
            ON u.id = a.created_by
         WHERE a.id = ?
         LIMIT 1"
    );

$announcementStmt->bind_param(
    'i',
    $announcementId
);

$announcementStmt->execute();

$announcementResult =
    $announcementStmt->get_result();

$announcement =
    $announcementResult->fetch_assoc();

$announcementStmt->close();

if (!$announcement) {

    $_SESSION['announcement_flash'] = [
        'type' => 'danger',
        'message' => 'Announcement not found.',
    ];

    header('Location: ../announcements.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load audiences
|--------------------------------------------------------------------------
*/

$audiences = [];

$audienceStmt =
    $conn->prepare(
        "SELECT
            id,
            audience_type,
            grade
         FROM announcement_audiences
         WHERE announcement_id = ?
         ORDER BY
            FIELD(
                audience_type,
                'Public',
                'Student',
                'Parent',
                'Teacher'
            ),
            grade ASC,
            id ASC"
    );

$audienceStmt->bind_param(
    'i',
    $announcementId
);

$audienceStmt->execute();

$audienceResult =
    $audienceStmt->get_result();

while (
    $row =
    $audienceResult->fetch_assoc()
) {
    $audiences[] = $row;
}

$audienceStmt->close();

/*
|--------------------------------------------------------------------------
| Existing audience state
|--------------------------------------------------------------------------
*/

$publicSelected = false;

$studentsSelected = false;
$studentsAllGrades = false;
$studentGrades = [];

$parentsSelected = false;
$parentsAllGrades = false;
$parentGrades = [];

$teachersSelected = false;

foreach ($audiences as $audience) {

    $type =
        (string) $audience['audience_type'];

    $grade =
        $audience['grade'] !== null
            ? (int) $audience['grade']
            : null;

    if ($type === 'Public') {
        $publicSelected = true;
    }

    if ($type === 'Student') {

        $studentsSelected = true;

        if ($grade === null) {
            $studentsAllGrades = true;
        } else {
            $studentGrades[] = $grade;
        }
    }

    if ($type === 'Parent') {

        $parentsSelected = true;

        if ($grade === null) {
            $parentsAllGrades = true;
        } else {
            $parentGrades[] = $grade;
        }
    }

    if ($type === 'Teacher') {
        $teachersSelected = true;
    }
}

/*
|--------------------------------------------------------------------------
| Load existing media
|--------------------------------------------------------------------------
*/

$media = [];

$mediaStmt =
    $conn->prepare(
        "SELECT
            id,
            media_type,
            file_path,
            original_name,
            mime_type,
            file_size,
            display_order
         FROM announcement_media
         WHERE announcement_id = ?
         ORDER BY display_order ASC, id ASC"
    );

$mediaStmt->bind_param(
    'i',
    $announcementId
);

$mediaStmt->execute();

$mediaResult =
    $mediaStmt->get_result();

while (
    $row =
    $mediaResult->fetch_assoc()
) {
    $media[] = $row;
}

$mediaStmt->close();

/*
|--------------------------------------------------------------------------
| Form values
|--------------------------------------------------------------------------
*/

$title =
    (string) ($announcement['title'] ?? '');

$content =
    (string) ($announcement['content'] ?? '');

$status =
    (string) ($announcement['status'] ?? 'Draft');

$lastDateEthiopian =
    gregorianToEthiopianInput(
        $announcement['closed_at'] ?? null
    );

$errors = [];

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $postedToken =
        $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $errors[] =
            'Invalid security token. Please refresh the page and try again.';

    } else {

        /*
        |--------------------------------------------------------------------------
        | Basic fields
        |--------------------------------------------------------------------------
        */

        $title =
            trim(
                (string) (
                    $_POST['title'] ?? ''
                )
            );

        $content =
            trim(
                (string) (
                    $_POST['content'] ?? ''
                )
            );

        $status =
            (string) (
                $_POST['status'] ?? 'Draft'
            );

        $lastDateEthiopian =
            trim(
                (string) (
                    $_POST['last_date'] ?? ''
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Validate title
        |--------------------------------------------------------------------------
        */

        if ($title === '') {

            $errors[] =
                'Please enter an announcement title.';

        } elseif (mb_strlen($title) > 255) {

            $errors[] =
                'The announcement title is too long.';
        }

        /*
        |--------------------------------------------------------------------------
        | Validate status
        |--------------------------------------------------------------------------
        */

        $allowedStatuses = [
            'Draft',
            'Published',
            'Closed',
        ];

        if (
            !in_array(
                $status,
                $allowedStatuses,
                true
            )
        ) {

            $errors[] =
                'Invalid announcement status.';
        }

        /*
        |--------------------------------------------------------------------------
        | Audiences
        |--------------------------------------------------------------------------
        */

        $selectedAudiences =
            $_POST['audiences'] ?? [];

        if (!is_array($selectedAudiences)) {
            $selectedAudiences = [];
        }

        $selectedAudiences =
            array_values(
                array_unique(
                    array_map(
                        'strval',
                        $selectedAudiences
                    )
                )
            );

        $validAudienceTypes = [
            'Public',
            'Student',
            'Parent',
            'Teacher',
        ];

        foreach (
            $selectedAudiences
            as $audienceType
        ) {

            if (
                !in_array(
                    $audienceType,
                    $validAudienceTypes,
                    true
                )
            ) {

                $errors[] =
                    'Invalid audience selected.';

                break;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Student grades
        |--------------------------------------------------------------------------
        */

        $studentGrades =
            $_POST['student_grades'] ?? [];

        if (!is_array($studentGrades)) {
            $studentGrades = [];
        }

        $studentGrades =
            array_values(
                array_unique(
                    array_map(
                        'intval',
                        $studentGrades
                    )
                )
            );

        $studentGrades =
            array_values(
                array_filter(
                    $studentGrades,
                    static fn (
                        int $grade
                    ): bool =>
                        $grade >= 1 &&
                        $grade <= 12
                )
            );

        /*
        |--------------------------------------------------------------------------
        | Parent grades
        |--------------------------------------------------------------------------
        */

        $parentGrades =
            $_POST['parent_grades'] ?? [];

        if (!is_array($parentGrades)) {
            $parentGrades = [];
        }

        $parentGrades =
            array_values(
                array_unique(
                    array_map(
                        'intval',
                        $parentGrades
                    )
                )
            );

        $parentGrades =
            array_values(
                array_filter(
                    $parentGrades,
                    static fn (
                        int $grade
                    ): bool =>
                        $grade >= 1 &&
                        $grade <= 12
                )
            );

        /*
        |--------------------------------------------------------------------------
        | All grades
        |--------------------------------------------------------------------------
        */

        $studentsAllGradesPost =
            isset(
                $_POST['students_all_grades']
            );

        $parentsAllGradesPost =
            isset(
                $_POST['parents_all_grades']
            );

        /*
        |--------------------------------------------------------------------------
        | Validate student audience
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                'Student',
                $selectedAudiences,
                true
            )
        ) {

            if (
                !$studentsAllGradesPost &&
                empty($studentGrades)
            ) {

                $errors[] =
                    'Please select All Grades or at least one student grade.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate parent audience
        |--------------------------------------------------------------------------
        */

        if (
            in_array(
                'Parent',
                $selectedAudiences,
                true
            )
        ) {

            if (
                !$parentsAllGradesPost &&
                empty($parentGrades)
            ) {

                $errors[] =
                    'Please select All Grades or at least one parent grade.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Last Date
        |--------------------------------------------------------------------------
        */

        $closedAtGregorian = null;

        if ($lastDateEthiopian !== '') {

            $ethiopianParts =
                parseEthiopianDate(
                    $lastDateEthiopian
                );

            if ($ethiopianParts === null) {

                $errors[] =
                    'Please enter a valid Ethiopian last date.';

            } else {

                /*
                 * EXACT conversion from the user's
                 * EthiopianCalendar class.
                 */
                $gregorianDate =
                    ethiopianToGregorianDate(
                        $lastDateEthiopian
                    );

                if ($gregorianDate === null) {

                    $errors[] =
                        'The Ethiopian last date could not be converted to Gregorian date.';

                } else {

                    $closedAtGregorian =
                        $gregorianDate .
                        ' 23:59:59';

                    /*
                    |--------------------------------------------------------------------------
                    | Compare against today
                    |--------------------------------------------------------------------------
                    */

                    $timezone =
                        new DateTimeZone(
                            'Africa/Addis_Ababa'
                        );

                    $today =
                        new DateTimeImmutable(
                            'today',
                            $timezone
                        );

                    $selectedDate =
                        new DateTimeImmutable(
                            $gregorianDate,
                            $timezone
                        );

                    if (
                        $selectedDate < $today
                    ) {

                        $errors[] =
                            'The last date cannot be earlier than today.';
                    }

                    /*
                     * Published announcements must expire
                     * after today.
                     */
                    if (
                        $status === 'Published' &&
                        $selectedDate <= $today
                    ) {

                        $errors[] =
                            'The last date must be in the future for a published announcement.';
                    }
                }
            }

        } elseif ($status === 'Published') {

            $errors[] =
                'A published announcement must have a last date.';
        }

        /*
        |--------------------------------------------------------------------------
        | Existing media removal
        |--------------------------------------------------------------------------
        */

        $removeMedia =
            $_POST['remove_media'] ?? [];

        if (!is_array($removeMedia)) {
            $removeMedia = [];
        }

        $removeMediaIds =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $removeMedia
                        ),
                        static fn (
                            int $id
                        ): bool =>
                            $id > 0
                    )
                )
            );

        /*
        |--------------------------------------------------------------------------
        | New image uploads
        |--------------------------------------------------------------------------
        */

        $newImages = [];

        $allowedImageTypes = [
            'image/jpeg',
            'image/png',
            'image/webp',
        ];

        $imageMaxSize =
            5 * 1024 * 1024;

        if (
            isset($_FILES['images']) &&
            is_array(
                $_FILES['images']['name'] ?? null
            )
        ) {

            $imageCount =
                count(
                    $_FILES['images']['name']
                );

            if ($imageCount > 10) {

                $errors[] =
                    'You can upload a maximum of 10 new images.';
            }

            for (
                $i = 0;
                $i < min($imageCount, 10);
                $i++
            ) {

                $uploadError =
                    (int) (
                        $_FILES['images']['error'][$i]
                        ?? UPLOAD_ERR_NO_FILE
                    );

                if (
                    $uploadError ===
                    UPLOAD_ERR_NO_FILE
                ) {
                    continue;
                }

                if (
                    $uploadError !==
                    UPLOAD_ERR_OK
                ) {

                    $errors[] =
                        'One of the images could not be uploaded.';

                    continue;
                }

                $tmpName =
                    (string) (
                        $_FILES['images']['tmp_name'][$i]
                    );

                $originalName =
                    (string) (
                        $_FILES['images']['name'][$i]
                    );

                $fileSize =
                    (int) (
                        $_FILES['images']['size'][$i]
                    );

                if (
                    $fileSize >
                    $imageMaxSize
                ) {

                    $errors[] =
                        'Each image must be 5 MB or smaller.';

                    continue;
                }

                $imageInfo =
                    @getimagesize(
                        $tmpName
                    );

                if ($imageInfo === false) {

                    $errors[] =
                        'One of the uploaded files is not a valid image.';

                    continue;
                }

                $mimeType =
                    strtolower(
                        (string) (
                            $imageInfo['mime'] ?? ''
                        )
                    );

                if (
                    !in_array(
                        $mimeType,
                        $allowedImageTypes,
                        true
                    )
                ) {

                    $errors[] =
                        'Only JPG, PNG, and WEBP images are allowed.';

                    continue;
                }

                $newImages[] = [
                    'tmp_name' =>
                        $tmpName,

                    'original_name' =>
                        $originalName,

                    'mime_type' =>
                        $mimeType,

                    'size' =>
                        $fileSize,
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | New attachments
        |--------------------------------------------------------------------------
        */

        $newAttachments = [];

        $attachmentMaxSize =
            10 * 1024 * 1024;

        $allowedAttachmentExtensions = [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'zip',
        ];

        if (
            isset($_FILES['attachments']) &&
            is_array(
                $_FILES['attachments']['name'] ?? null
            )
        ) {

            $attachmentCount =
                count(
                    $_FILES['attachments']['name']
                );

            if ($attachmentCount > 10) {

                $errors[] =
                    'You can upload a maximum of 10 new attachments.';
            }

            for (
                $i = 0;
                $i < min($attachmentCount, 10);
                $i++
            ) {

                $uploadError =
                    (int) (
                        $_FILES['attachments']['error'][$i]
                        ?? UPLOAD_ERR_NO_FILE
                    );

                if (
                    $uploadError ===
                    UPLOAD_ERR_NO_FILE
                ) {
                    continue;
                }

                if (
                    $uploadError !==
                    UPLOAD_ERR_OK
                ) {

                    $errors[] =
                        'One of the attachments could not be uploaded.';

                    continue;
                }

                $tmpName =
                    (string) (
                        $_FILES['attachments']['tmp_name'][$i]
                    );

                $originalName =
                    (string) (
                        $_FILES['attachments']['name'][$i]
                    );

                $fileSize =
                    (int) (
                        $_FILES['attachments']['size'][$i]
                    );

                if (
                    $fileSize >
                    $attachmentMaxSize
                ) {

                    $errors[] =
                        'Each attachment must be 10 MB or smaller.';

                    continue;
                }

                $extension =
                    strtolower(
                        pathinfo(
                            $originalName,
                            PATHINFO_EXTENSION
                        )
                    );

                if (
                    !in_array(
                        $extension,
                        $allowedAttachmentExtensions,
                        true
                    )
                ) {

                    $errors[] =
                        'One of the attachments has an unsupported file type.';

                    continue;
                }

                $mimeType = null;

                if (
                    function_exists(
                        'mime_content_type'
                    )
                ) {

                    $detectedMime =
                        mime_content_type(
                            $tmpName
                        );

                    if (
                        is_string(
                            $detectedMime
                        )
                    ) {
                        $mimeType =
                            $detectedMime;
                    }
                }

                $newAttachments[] = [
                    'tmp_name' =>
                        $tmpName,

                    'original_name' =>
                        $originalName,

                    'mime_type' =>
                        $mimeType,

                    'size' =>
                        $fileSize,
                ];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Save changes
        |--------------------------------------------------------------------------
        */

        if (empty($errors)) {

            $imageDirectory =
                '../../uploads/announcements/images/';

            $attachmentDirectory =
                '../../uploads/announcements/attachments/';

            if (!is_dir($imageDirectory)) {

                if (
                    !mkdir(
                        $imageDirectory,
                        0755,
                        true
                    ) &&
                    !is_dir($imageDirectory)
                ) {

                    $errors[] =
                        'Could not create the image upload directory.';
                }
            }

            if (!is_dir($attachmentDirectory)) {

                if (
                    !mkdir(
                        $attachmentDirectory,
                        0755,
                        true
                    ) &&
                    !is_dir($attachmentDirectory)
                ) {

                    $errors[] =
                        'Could not create the attachment upload directory.';
                }
            }
        }

        if (empty($errors)) {

            $movedFiles = [];

            try {

                $conn->begin_transaction();

                /*
                |--------------------------------------------------------------------------
                | Published date
                |--------------------------------------------------------------------------
                */

                $publishedAt =
                    $announcement['published_at'];

                if (
                    $status === 'Published' &&
                    empty($publishedAt)
                ) {

                    $publishedAt =
                        (new DateTimeImmutable(
                            'now',
                            new DateTimeZone(
                                'Africa/Addis_Ababa'
                            )
                        ))->format(
                            'Y-m-d H:i:s'
                        );
                }

                if ($status !== 'Published') {
                    $publishedAt = null;
                }

                /*
                |--------------------------------------------------------------------------
                | Update announcement
                |--------------------------------------------------------------------------
                */

                $updateStmt =
                    $conn->prepare(
                        "UPDATE announcements
                         SET
                            title = ?,
                            content = ?,
                            status = ?,
                            published_at = ?,
                            closed_at = ?,
                            updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?
                         LIMIT 1"
                    );

                $updateStmt->bind_param(
                    'sssssi',
                    $title,
                    $content,
                    $status,
                    $publishedAt,
                    $closedAtGregorian,
                    $announcementId
                );

                $updateStmt->execute();
                $updateStmt->close();

                /*
                |--------------------------------------------------------------------------
                | Replace audiences
                |--------------------------------------------------------------------------
                */

                $deleteAudienceStmt =
                    $conn->prepare(
                        "DELETE FROM announcement_audiences
                         WHERE announcement_id = ?"
                    );

                $deleteAudienceStmt->bind_param(
                    'i',
                    $announcementId
                );

                $deleteAudienceStmt->execute();
                $deleteAudienceStmt->close();

                $insertAudienceStmt =
                    $conn->prepare(
                        "INSERT INTO announcement_audiences
                        (
                            announcement_id,
                            audience_type,
                            grade
                        )
                        VALUES (?, ?, ?)"
                    );

                /*
                |--------------------------------------------------------------------------
                | Public
                |--------------------------------------------------------------------------
                */

                if (
                    in_array(
                        'Public',
                        $selectedAudiences,
                        true
                    )
                ) {

                    $audienceType =
                        'Public';

                    $grade = null;

                    $insertAudienceStmt->bind_param(
                        'isi',
                        $announcementId,
                        $audienceType,
                        $grade
                    );

                    $insertAudienceStmt->execute();
                }

                /*
                |--------------------------------------------------------------------------
                | Students
                |--------------------------------------------------------------------------
                */

                if (
                    in_array(
                        'Student',
                        $selectedAudiences,
                        true
                    )
                ) {

                    $audienceType =
                        'Student';

                    if (
                        $studentsAllGradesPost
                    ) {

                        $grade = null;

                        $insertAudienceStmt->bind_param(
                            'isi',
                            $announcementId,
                            $audienceType,
                            $grade
                        );

                        $insertAudienceStmt->execute();

                    } else {

                        foreach (
                            $studentGrades
                            as $selectedGrade
                        ) {

                            $grade =
                                $selectedGrade;

                            $insertAudienceStmt->bind_param(
                                'isi',
                                $announcementId,
                                $audienceType,
                                $grade
                            );

                            $insertAudienceStmt->execute();
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Parents
                |--------------------------------------------------------------------------
                */

                if (
                    in_array(
                        'Parent',
                        $selectedAudiences,
                        true
                    )
                ) {

                    $audienceType =
                        'Parent';

                    if (
                        $parentsAllGradesPost
                    ) {

                        $grade = null;

                        $insertAudienceStmt->bind_param(
                            'isi',
                            $announcementId,
                            $audienceType,
                            $grade
                        );

                        $insertAudienceStmt->execute();

                    } else {

                        foreach (
                            $parentGrades
                            as $selectedGrade
                        ) {

                            $grade =
                                $selectedGrade;

                            $insertAudienceStmt->bind_param(
                                'isi',
                                $announcementId,
                                $audienceType,
                                $grade
                            );

                            $insertAudienceStmt->execute();
                        }
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Teachers
                |--------------------------------------------------------------------------
                */

                if (
                    in_array(
                        'Teacher',
                        $selectedAudiences,
                        true
                    )
                ) {

                    $audienceType =
                        'Teacher';

                    $grade = null;

                    $insertAudienceStmt->bind_param(
                        'isi',
                        $announcementId,
                        $audienceType,
                        $grade
                    );

                    $insertAudienceStmt->execute();
                }

                $insertAudienceStmt->close();

                /*
                |--------------------------------------------------------------------------
                | Remove selected existing media
                |--------------------------------------------------------------------------
                */

                if (
                    !empty($removeMediaIds)
                ) {

                    $mediaLookupStmt =
                        $conn->prepare(
                            "SELECT
                                id,
                                file_path
                             FROM announcement_media
                             WHERE announcement_id = ?
                               AND id = ?"
                        );

                    $clearBlockMediaStmt =
                        $conn->prepare(
                            "UPDATE announcement_content_blocks
                             SET media_id = NULL
                             WHERE announcement_id = ?
                               AND media_id = ?"
                        );

                    $deleteMediaStmt =
                        $conn->prepare(
                            "DELETE FROM announcement_media
                             WHERE announcement_id = ?
                               AND id = ?"
                        );

                    foreach (
                        $removeMediaIds
                        as $mediaId
                    ) {

                        $mediaLookupStmt->bind_param(
                            'ii',
                            $announcementId,
                            $mediaId
                        );

                        $mediaLookupStmt->execute();

                        $lookupResult =
                            $mediaLookupStmt->get_result();

                        $mediaRow =
                            $lookupResult->fetch_assoc();

                        /*
                         * Clear content-block references first.
                         */
                        $clearBlockMediaStmt->bind_param(
                            'ii',
                            $announcementId,
                            $mediaId
                        );

                        $clearBlockMediaStmt->execute();

                        /*
                         * Delete media database row.
                         */
                        $deleteMediaStmt->bind_param(
                            'ii',
                            $announcementId,
                            $mediaId
                        );

                        $deleteMediaStmt->execute();

                        /*
                         * Delete physical file.
                         */
                        if ($mediaRow) {

                            $filePath =
                                (string) (
                                    $mediaRow['file_path']
                                    ?? ''
                                );

                            if ($filePath !== '') {

                                $physicalPath =
                                    '../../' .
                                    ltrim(
                                        $filePath,
                                        '/'
                                    );

                                if (
                                    is_file(
                                        $physicalPath
                                    )
                                ) {

                                    @unlink(
                                        $physicalPath
                                    );
                                }
                            }
                        }
                    }

                    $mediaLookupStmt->close();
                    $clearBlockMediaStmt->close();
                    $deleteMediaStmt->close();
                }

                /*
                |--------------------------------------------------------------------------
                | Determine next display order
                |--------------------------------------------------------------------------
                */

                $orderStmt =
                    $conn->prepare(
                        "SELECT
                            COALESCE(
                                MAX(display_order),
                                -1
                            ) + 1 AS next_order
                         FROM announcement_media
                         WHERE announcement_id = ?"
                    );

                $orderStmt->bind_param(
                    'i',
                    $announcementId
                );

                $orderStmt->execute();

                $orderResult =
                    $orderStmt->get_result();

                $orderRow =
                    $orderResult->fetch_assoc();

                $nextOrder =
                    (int) (
                        $orderRow['next_order']
                        ?? 0
                    );

                $orderStmt->close();

                /*
                |--------------------------------------------------------------------------
                | Media insert statement
                |--------------------------------------------------------------------------
                */

                $mediaInsertStmt =
                    $conn->prepare(
                        "INSERT INTO announcement_media
                        (
                            announcement_id,
                            media_type,
                            file_path,
                            original_name,
                            mime_type,
                            file_size,
                            display_order
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );

                /*
                |--------------------------------------------------------------------------
                | Save images
                |--------------------------------------------------------------------------
                */

                foreach (
                    $newImages
                    as $image
                ) {

                    $extension =
                        strtolower(
                            pathinfo(
                                $image['original_name'],
                                PATHINFO_EXTENSION
                            )
                        );

                    if ($extension === '') {
                        $extension = 'jpg';
                    }

                    $fileName =
                        bin2hex(
                            random_bytes(16)
                        ) .
                        '.' .
                        $extension;

                    $destination =
                        $imageDirectory .
                        $fileName;

                    if (
                        !move_uploaded_file(
                            $image['tmp_name'],
                            $destination
                        )
                    ) {

                        throw new RuntimeException(
                            'Failed to save uploaded image.'
                        );
                    }

                    $movedFiles[] =
                        $destination;

                    $filePath =
                        'uploads/announcements/images/' .
                        $fileName;

                    $mediaType =
                        'Image';

                    $originalName =
                        $image['original_name'];

                    $mimeType =
                        $image['mime_type'];

                    $fileSize =
                        (int) $image['size'];

                    $displayOrder =
                        $nextOrder++;

                    $mediaInsertStmt->bind_param(
                        'issssii',
                        $announcementId,
                        $mediaType,
                        $filePath,
                        $originalName,
                        $mimeType,
                        $fileSize,
                        $displayOrder
                    );

                    $mediaInsertStmt->execute();
                }

                /*
                |--------------------------------------------------------------------------
                | Save attachments
                |--------------------------------------------------------------------------
                */

                foreach (
                    $newAttachments
                    as $attachment
                ) {

                    $extension =
                        strtolower(
                            pathinfo(
                                $attachment['original_name'],
                                PATHINFO_EXTENSION
                            )
                        );

                    $fileName =
                        bin2hex(
                            random_bytes(16)
                        ) .
                        '.' .
                        $extension;

                    $destination =
                        $attachmentDirectory .
                        $fileName;

                    if (
                        !move_uploaded_file(
                            $attachment['tmp_name'],
                            $destination
                        )
                    ) {

                        throw new RuntimeException(
                            'Failed to save uploaded attachment.'
                        );
                    }

                    $movedFiles[] =
                        $destination;

                    $filePath =
                        'uploads/announcements/attachments/' .
                        $fileName;

                    $mediaType =
                        'Attachment';

                    $originalName =
                        $attachment['original_name'];

                    $mimeType =
                        $attachment['mime_type'];

                    $fileSize =
                        (int) $attachment['size'];

                    $displayOrder =
                        $nextOrder++;

                    $mediaInsertStmt->bind_param(
                        'issssii',
                        $announcementId,
                        $mediaType,
                        $filePath,
                        $originalName,
                        $mimeType,
                        $fileSize,
                        $displayOrder
                    );

                    $mediaInsertStmt->execute();
                }

                $mediaInsertStmt->close();

                /*
                |--------------------------------------------------------------------------
                | Commit
                |--------------------------------------------------------------------------
                */

                $conn->commit();

                $_SESSION['announcement_flash'] = [
                    'type' =>
                        'success',

                    'message' =>
                        'Announcement updated successfully.',
                ];

                redirectToView(
                    $announcementId
                );

            } catch (Throwable $exception) {

                try {
                    $conn->rollback();
                } catch (Throwable $rollbackException) {
                    // Ignore rollback failure.
                }

                /*
                 * Remove newly moved files if transaction failed.
                 */
                foreach (
                    $movedFiles
                    as $movedFile
                ) {

                    if (
                        is_file(
                            $movedFile
                        )
                    ) {

                        @unlink(
                            $movedFile
                        );
                    }
                }

                $errors[] =
                    'The announcement could not be updated. Please try again.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Restore submitted audience selections
    |--------------------------------------------------------------------------
    */

    $publicSelected =
        in_array(
            'Public',
            $selectedAudiences ?? [],
            true
        );

    $studentsSelected =
        in_array(
            'Student',
            $selectedAudiences ?? [],
            true
        );

    $parentsSelected =
        in_array(
            'Parent',
            $selectedAudiences ?? [],
            true
        );

    $teachersSelected =
        in_array(
            'Teacher',
            $selectedAudiences ?? [],
            true
        );

    $studentsAllGrades =
        isset(
            $_POST['students_all_grades']
        );

    $parentsAllGrades =
        isset(
            $_POST['parents_all_grades']
        );
}

/*
|--------------------------------------------------------------------------
| Today's Ethiopian date
|--------------------------------------------------------------------------
*/

$today =
    EthiopianCalendar::todayFormatted();

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Edit Announcement - BKHS School
    </title>
     <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --background: #f5f7fb;
            --card: #fff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: 'Inter', sans-serif;
        }

        a {
            text-decoration: none;
        }

        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 24px;
            color: #fff;
            font-size: 20px;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,.06);
        }

        .sidebar-brand i {
            color: #818cf8;
            font-size: 25px;
            margin-right: 10px;
        }

        .sidebar-nav {
            padding: 18px 12px;
            overflow-y: auto;
            flex: 1;
        }

        .nav-section-title {
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 10px 12px 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #9ca3af;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
            transform: translateX(2px);
        }

        .sidebar-link.active {
            background: rgba(79,70,229,.18);
            color: #fff;
        }

        .sidebar-link.active i {
            color: #818cf8;
        }

        .sidebar-bottom {
            padding: 14px 12px 18px;
            border-top: 1px solid rgba(255,255,255,.06);
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .page-title {
            margin: 0;
            font-size: 19px;
            font-weight: 700;
        }

        .today {
            color: var(--muted);
            font-size: 12px;
            margin-top: 3px;
        }

        .mobile-menu {
            display: none;
            border: 0;
            background: #f3f4f6;
            width: 40px;
            height: 40px;
            border-radius: 9px;
            font-size: 20px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .principal-info {
            text-align: right;
        }

        .principal-name {
            font-size: 13px;
            font-weight: 700;
        }

        .principal-role {
            color: var(--muted);
            font-size: 11px;
        }

        .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            overflow: hidden;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            flex-shrink: 0;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content {
            padding: 28px 30px 40px;
        }

        .breadcrumb-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 12px;
            margin-bottom: 18px;
        }

        .breadcrumb-wrap a {
            color: var(--primary);
            font-weight: 600;
        }

        .form-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 3px 14px rgba(15,23,42,.03);
            margin-bottom: 18px;
        }

        .form-card-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .form-card-title {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
        }

        .form-card-body {
            padding: 22px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 7px;
        }

        .form-control,
        .form-select {
            border: 1px solid var(--border);
            border-radius: 9px;
            font-size: 12px;
            padding: 10px 12px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #a5b4fc;
            box-shadow: 0 0 0 .2rem rgba(79,70,229,.10);
        }

        textarea.form-control {
            min-height: 190px;
            resize: vertical;
            line-height: 1.7;
        }

        .help-text {
            color: var(--muted);
            font-size: 10px;
            margin-top: 5px;
        }

        .audience-option {
            border: 1px solid var(--border);
            border-radius: 11px;
            padding: 13px;
            margin-bottom: 10px;
            transition: all .2s ease;
        }

        .audience-option:hover {
            border-color: #c7d2fe;
            background: #fafaff;
        }

        .audience-main {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .audience-main label {
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .grades-box {
            margin-top: 12px;
            padding: 12px;
            background: #f9fafb;
            border-radius: 9px;
            border: 1px solid var(--border);
        }

        .grades-title {
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
            margin-bottom: 9px;
        }

        .grade-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 7px;
        }

        .grade-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 10px;
        }

        .all-grade-label {
            font-size: 11px;
            font-weight: 600;
            margin-bottom: 10px;
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .date-preview {
            margin-top: 8px;
            border-radius: 9px;
            background: #f5f3ff;
            border: 1px solid #ddd6fe;
            padding: 10px 12px;
            font-size: 11px;
            color: #5b21b6;
        }

        .existing-media {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .media-row {
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
        }

        .media-thumb {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .media-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .media-details {
            flex: 1;
            min-width: 0;
        }

        .media-name {
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .media-type {
            color: var(--muted);
            font-size: 10px;
            margin-top: 3px;
        }

        .remove-label {
            color: #dc2626;
            font-size: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .upload-box {
            border: 1px dashed #c7d2fe;
            background: #fafaff;
            border-radius: 10px;
            padding: 15px;
        }

        .upload-title {
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .upload-help {
            color: var(--muted);
            font-size: 10px;
            margin-bottom: 10px;
        }

        .action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 15px;
            position: sticky;
            bottom: 15px;
            z-index: 20;
            box-shadow: 0 5px 25px rgba(15,23,42,.08);
        }

        .action-left {
            color: var(--muted);
            font-size: 11px;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-primary-custom {
            background: var(--primary);
            border: 1px solid var(--primary);
            color: #fff;
            border-radius: 9px;
            padding: 9px 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff;
        }

        .btn-light-custom {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 9px;
            padding: 9px 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .btn-light-custom:hover {
            background: #f9fafb;
        }

        .alert-custom {
            border-radius: 10px;
            font-size: 12px;
            padding: 12px 14px;
            margin-bottom: 18px;
        }

        .alert-custom ul {
            margin: 0;
            padding-left: 20px;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 900px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 22px 18px 35px;
            }

            .grade-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        @media (max-width: 650px) {

            .principal-info {
                display: none;
            }

            .content {
                padding: 18px 13px 30px;
            }

            .form-card-body {
                padding: 17px;
            }

            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .action-buttons {
                width: 100%;
            }

            .action-buttons a,
            .action-buttons button {
                flex: 1;
            }

            .grade-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 430px) {

            .grade-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

    </style>

</head>

<body>

<div
    class="overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <i class="bi bi-mortarboard-fill"></i>

        BKHS School

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main
        </div>

        <a
            href="../dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="../announcements.php"
            class="sidebar-link active"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

        <a
            href="../subject-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-book-half"></i>
            <span>Subject Assignment</span>
        </a>

        <a
            href="../homeroom-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>
            <span>Homeroom Assignment</span>
        </a>

        <a
            href="../student-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-check-fill"></i>
            <span>Student Assignment</span>
        </a>

        <a
            href="../attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a
            href="../roster.php"
            class="sidebar-link"
        >
            <i class="bi bi-people-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="../certificate.php"
            class="sidebar-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="../result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="nav-section-title mt-2">
            Account
        </div>

        <a
            href="../profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

    </nav>

    <div class="sidebar-bottom">

        <a
            href="../../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>

<!-- Main -->

<div class="main">

    <!-- Topbar -->

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu"
                id="mobileMenu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Edit Announcement
                </h1>

                <div class="today">
                    <?= e($today) ?>
                </div>

            </div>

        </div>

        <div class="topbar-right">

            <div class="principal-info">

                <div class="principal-name">
                    <?= e(
                        (string) $principal['full_name']
                    ) ?>
                </div>

                <div class="principal-role">
                    Principal
                </div>

            </div>

            <div class="avatar">

                <?php if ($principalPhotoUrl !== ''): ?>

                    <img
                        src="<?= e($principalPhotoUrl) ?>"
                        alt="Principal"
                    >

                <?php else: ?>

                    <i class="bi bi-person-fill"></i>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <main class="content">

        <!-- Breadcrumb -->

        <div class="breadcrumb-wrap">

            <a href="../announcements.php">

                <i class="bi bi-megaphone-fill"></i>

                Announcements

            </a>

            <i class="bi bi-chevron-right"></i>

            <a
                href="view.php?id=<?= (int) $announcementId ?>"
            >
                View
            </a>

            <i class="bi bi-chevron-right"></i>

            <span>
                Edit
            </span>

        </div>

        <!-- Errors -->

        <?php if (!empty($errors)): ?>

            <div class="alert alert-danger alert-custom">

                <ul>

                    <?php foreach (
                        $errors
                        as $error
                    ): ?>

                        <li>
                            <?= e($error) ?>
                        </li>

                    <?php endforeach; ?>

                </ul>

            </div>

        <?php endif; ?>

        <form
            method="post"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <!-- Basic Information -->

            <section class="form-card">

                <div class="form-card-header">

                    <h2 class="form-card-title">

                        <i class="bi bi-pencil-square me-2"></i>

                        Basic Information

                    </h2>

                </div>

                <div class="form-card-body">

                    <div class="mb-3">

                        <label
                            for="title"
                            class="form-label"
                        >
                            Announcement Title
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            id="title"
                            name="title"
                            maxlength="255"
                            value="<?= e($title) ?>"
                            required
                        >

                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">

                            <label
                                for="status"
                                class="form-label"
                            >
                                Status
                            </label>

                            <select
                                class="form-select"
                                id="status"
                                name="status"
                            >

                                <option
                                    value="Draft"
                                    <?= $status === 'Draft'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Draft
                                </option>

                                <option
                                    value="Published"
                                    <?= $status === 'Published'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Published
                                </option>

                                <option
                                    value="Closed"
                                    <?= $status === 'Closed'
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Closed
                                </option>

                            </select>

                        </div>

                        <div class="col-md-6">

                            <label
                                for="last_date"
                                class="form-label"
                            >
                                Last Date
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                id="last_date"
                                name="last_date"
                                value="<?= e(
                                    $lastDateEthiopian
                                ) ?>"
                                placeholder="2019-01-20"
                                inputmode="numeric"
                                autocomplete="off"
                            >

                            <div class="help-text">

                                Ethiopian date:
                                YYYY-MM-DD

                            </div>

                            <div
                                class="date-preview"
                                id="datePreview"
                            >

                                <strong>
                                    Last Date:
                                </strong>

                                <?php if (
                                    $lastDateEthiopian !== ''
                                ): ?>

                                    <?= e(
                                        $lastDateEthiopian
                                    ) ?>

                                <?php else: ?>

                                    No last date selected.

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>

            </section>

            <!-- Audience -->

            <section class="form-card">

                <div class="form-card-header">

                    <h2 class="form-card-title">

                        <i class="bi bi-people-fill me-2"></i>

                        Audience

                    </h2>

                </div>

                <div class="form-card-body">

                    <!-- Public -->

                    <div class="audience-option">

                        <div class="audience-main">

                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="audiencePublic"
                                name="audiences[]"
                                value="Public"
                                <?= $publicSelected
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                for="audiencePublic"
                            >
                                Public
                            </label>

                        </div>

                    </div>

                    <!-- Students -->

                    <div class="audience-option">

                        <div class="audience-main">

                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="audienceStudents"
                                name="audiences[]"
                                value="Student"
                                <?= $studentsSelected
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                for="audienceStudents"
                            >
                                Students
                            </label>

                        </div>

                        <div
                            class="grades-box"
                            id="studentsGradesBox"
                            style="<?= $studentsSelected
                                ? ''
                                : 'display:none;' ?>"
                        >

                            <div class="grades-title">
                                Student Grades
                            </div>

                            <label class="all-grade-label">

                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="studentsAllGrades"
                                    name="students_all_grades"
                                    <?= $studentsAllGrades
                                        ? 'checked'
                                        : '' ?>
                                >

                                All Grades

                            </label>

                            <div class="grade-grid">

                                <?php for (
                                    $grade = 1;
                                    $grade <= 12;
                                    $grade++
                                ): ?>

                                    <label class="grade-item">

                                        <input
                                            type="checkbox"
                                            class="form-check-input student-grade"
                                            name="student_grades[]"
                                            value="<?= $grade ?>"
                                            <?= in_array(
                                                $grade,
                                                $studentGrades,
                                                true
                                            )
                                                ? 'checked'
                                                : '' ?>
                                        >

                                        Grade <?= $grade ?>

                                    </label>

                                <?php endfor; ?>

                            </div>

                        </div>

                    </div>

                    <!-- Parents -->

                    <div class="audience-option">

                        <div class="audience-main">

                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="audienceParents"
                                name="audiences[]"
                                value="Parent"
                                <?= $parentsSelected
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                for="audienceParents"
                            >
                                Parents
                            </label>

                        </div>

                        <div
                            class="grades-box"
                            id="parentsGradesBox"
                            style="<?= $parentsSelected
                                ? ''
                                : 'display:none;' ?>"
                        >

                            <div class="grades-title">
                                Parent Grades
                            </div>

                            <label class="all-grade-label">

                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="parentsAllGrades"
                                    name="parents_all_grades"
                                    <?= $parentsAllGrades
                                        ? 'checked'
                                        : '' ?>
                                >

                                All Grades

                            </label>

                            <div class="grade-grid">

                                <?php for (
                                    $grade = 1;
                                    $grade <= 12;
                                    $grade++
                                ): ?>

                                    <label class="grade-item">

                                        <input
                                            type="checkbox"
                                            class="form-check-input parent-grade"
                                            name="parent_grades[]"
                                            value="<?= $grade ?>"
                                            <?= in_array(
                                                $grade,
                                                $parentGrades,
                                                true
                                            )
                                                ? 'checked'
                                                : '' ?>
                                        >

                                        Grade <?= $grade ?>

                                    </label>

                                <?php endfor; ?>

                            </div>

                        </div>

                    </div>

                    <!-- Teachers -->

                    <div class="audience-option">

                        <div class="audience-main">

                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="audienceTeachers"
                                name="audiences[]"
                                value="Teacher"
                                <?= $teachersSelected
                                    ? 'checked'
                                    : '' ?>
                            >

                            <label
                                for="audienceTeachers"
                            >
                                Teachers — All Teachers
                            </label>

                        </div>

                    </div>

                </div>

            </section>

            <!-- Content -->

            <section class="form-card">

                <div class="form-card-header">

                    <h2 class="form-card-title">

                        <i class="bi bi-file-text me-2"></i>

                        Content

                    </h2>

                </div>

                <div class="form-card-body">

                    <label
                        for="content"
                        class="form-label"
                    >
                        Announcement Content
                    </label>

                    <textarea
                        class="form-control"
                        id="content"
                        name="content"
                        placeholder="Write the announcement content..."
                    ><?= e($content) ?></textarea>

                </div>

            </section>

            <!-- Existing Media -->

            <section class="form-card">

                <div class="form-card-header">

                    <h2 class="form-card-title">

                        <i class="bi bi-images me-2"></i>

                        Existing Media & Files

                    </h2>

                </div>

                <div class="form-card-body">

                    <?php if (!empty($media)): ?>

                        <div class="existing-media">

                            <?php foreach (
                                $media
                                as $item
                            ): ?>

                                <?php

                                $mimeType =
                                    $item['mime_type']
                                    ?? null;

                                $isImage =
                                    $item['media_type'] === 'Image'
                                    ||
                                    isImageMime(
                                        $mimeType
                                    );

                                ?>

                                <div class="media-row">

                                    <div class="media-thumb">

                                        <?php if ($isImage): ?>

                                            <img
                                                src="<?= e(
                                                    mediaUrl(
                                                        (string)
                                                        $item['file_path']
                                                    )
                                                ) ?>"
                                                alt="<?= e(
                                                    (string)
                                                    $item['original_name']
                                                ) ?>"
                                            >

                                        <?php else: ?>

                                            <i class="bi bi-file-earmark-text"></i>

                                        <?php endif; ?>

                                    </div>

                                    <div class="media-details">

                                        <div
                                            class="media-name"
                                            title="<?= e(
                                                (string)
                                                $item['original_name']
                                            ) ?>"
                                        >
                                            <?= e(
                                                (string)
                                                $item['original_name']
                                            ) ?>
                                        </div>

                                        <div class="media-type">

                                            <?= e(
                                                (string)
                                                $item['media_type']
                                            ) ?>

                                            <?php if (
                                                !empty(
                                                    $item['file_size']
                                                )
                                            ): ?>

                                                ·

                                                <?= e(
                                                    number_format(
                                                        ((int)
                                                        $item['file_size'])
                                                        / 1024,
                                                        0
                                                    )
                                                ) ?>

                                                KB

                                            <?php endif; ?>

                                        </div>

                                    </div>

                                    <label class="remove-label">

                                        <input
                                            type="checkbox"
                                            name="remove_media[]"
                                            value="<?= (int) $item['id'] ?>"
                                            class="form-check-input"
                                        >

                                        Remove

                                    </label>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <div class="help-text">
                            This announcement has no existing media.
                        </div>

                    <?php endif; ?>

                </div>

            </section>

            <!-- New Media -->

            <section class="form-card">

                <div class="form-card-header">

                    <h2 class="form-card-title">

                        <i class="bi bi-cloud-arrow-up me-2"></i>

                        Add New Media

                    </h2>

                </div>

                <div class="form-card-body">

                    <div class="row g-3">

                        <div class="col-md-6">

                            <div class="upload-box">

                                <div class="upload-title">
                                    Images
                                </div>

                                <div class="upload-help">

                                    JPG, PNG, WEBP ·
                                    Maximum 5 MB each ·
                                    Maximum 10 files

                                </div>

                                <input
                                    type="file"
                                    class="form-control"
                                    name="images[]"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    multiple
                                >

                            </div>

                        </div>

                        <div class="col-md-6">

                            <div class="upload-box">

                                <div class="upload-title">
                                    Attachments
                                </div>

                                <div class="upload-help">

                                    PDF, DOC, DOCX, XLS, XLSX,
                                    PPT, PPTX, TXT, ZIP ·
                                    Maximum 10 MB each

                                </div>

                                <input
                                    type="file"
                                    class="form-control"
                                    name="attachments[]"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
                                    multiple
                                >

                            </div>

                        </div>

                    </div>

                </div>

            </section>

            <!-- Action -->

            <div class="action-bar">

                <div class="action-left">

                    <i class="bi bi-info-circle me-1"></i>

                    Changes will be saved to this announcement.

                </div>

                <div class="action-buttons">

                    <a
                        href="view.php?id=<?= (int) $announcementId ?>"
                        class="btn-light-custom"
                    >
                        Cancel
                    </a>

                    <button
                        type="submit"
                        class="btn-primary-custom"
                    >

                        <i class="bi bi-check-lg me-1"></i>

                        Save Changes

                    </button>

                </div>

            </div>

        </form>

    </main>

</div>

<script>

/*
|--------------------------------------------------------------------------
| Mobile sidebar
|--------------------------------------------------------------------------
*/

const mobileMenu =
    document.getElementById('mobileMenu');

const sidebar =
    document.getElementById('sidebar');

const overlay =
    document.getElementById('sidebarOverlay');

function openSidebar() {

    sidebar.classList.add('open');

    overlay.classList.add('show');

    document.body.style.overflow =
        'hidden';
}

function closeSidebar() {

    sidebar.classList.remove('open');

    overlay.classList.remove('show');

    document.body.style.overflow =
        '';
}

if (mobileMenu) {

    mobileMenu.addEventListener(
        'click',
        openSidebar
    );
}

if (overlay) {

    overlay.addEventListener(
        'click',
        closeSidebar
    );
}

document
    .querySelectorAll('.sidebar-link')
    .forEach(
        link => {

            link.addEventListener(
                'click',
                () => {

                    if (
                        window.innerWidth <= 900
                    ) {
                        closeSidebar();
                    }
                }
            );
        }
    );

window.addEventListener(
    'resize',
    () => {

        if (
            window.innerWidth > 900
        ) {
            closeSidebar();
        }
    }
);

/*
|--------------------------------------------------------------------------
| Students
|--------------------------------------------------------------------------
*/

const studentsCheck =
    document.getElementById(
        'audienceStudents'
    );

const studentsBox =
    document.getElementById(
        'studentsGradesBox'
    );

const studentsAll =
    document.getElementById(
        'studentsAllGrades'
    );

const studentGrades =
    document.querySelectorAll(
        '.student-grade'
    );

function updateStudentGrades() {

    if (!studentsCheck.checked) {

        studentsBox.style.display =
            'none';

        return;
    }

    studentsBox.style.display =
        '';

    if (studentsAll.checked) {

        studentGrades.forEach(
            checkbox => {

                checkbox.checked =
                    false;

                checkbox.disabled =
                    true;
            }
        );

    } else {

        studentGrades.forEach(
            checkbox => {

                checkbox.disabled =
                    false;
            }
        );
    }
}

studentsCheck.addEventListener(
    'change',
    updateStudentGrades
);

studentsAll.addEventListener(
    'change',
    updateStudentGrades
);

/*
|--------------------------------------------------------------------------
| Parents
|--------------------------------------------------------------------------
*/

const parentsCheck =
    document.getElementById(
        'audienceParents'
    );

const parentsBox =
    document.getElementById(
        'parentsGradesBox'
    );

const parentsAll =
    document.getElementById(
        'parentsAllGrades'
    );

const parentGrades =
    document.querySelectorAll(
        '.parent-grade'
    );

function updateParentGrades() {

    if (!parentsCheck.checked) {

        parentsBox.style.display =
            'none';

        return;
    }

    parentsBox.style.display =
        '';

    if (parentsAll.checked) {

        parentGrades.forEach(
            checkbox => {

                checkbox.checked =
                    false;

                checkbox.disabled =
                    true;
            }
        );

    } else {

        parentGrades.forEach(
            checkbox => {

                checkbox.disabled =
                    false;
            }
        );
    }
}

parentsCheck.addEventListener(
    'change',
    updateParentGrades
);

parentsAll.addEventListener(
    'change',
    updateParentGrades
);

updateStudentGrades();
updateParentGrades();

/*
|--------------------------------------------------------------------------
| Ethiopian Last Date preview
|--------------------------------------------------------------------------
*/

const lastDateInput =
    document.getElementById(
        'last_date'
    );

const datePreview =
    document.getElementById(
        'datePreview'
    );

function updateDatePreview() {

    const value =
        lastDateInput.value.trim();

    if (value === '') {

        datePreview.innerHTML =
            '<strong>Last Date:</strong> No last date selected.';

        return;
    }

    const parts =
        value.split('-');

    if (parts.length !== 3) {

        datePreview.innerHTML =
            '<strong>Last Date:</strong> Invalid Ethiopian date format.';

        return;
    }

    const year =
        parseInt(
            parts[0],
            10
        );

    const month =
        parseInt(
            parts[1],
            10
        );

    const day =
        parseInt(
            parts[2],
            10
        );

    let valid = true;

    if (
        Number.isNaN(year) ||
        Number.isNaN(month) ||
        Number.isNaN(day)
    ) {
        valid = false;
    }

    if (
        month < 1 ||
        month > 13
    ) {
        valid = false;
    }

    let maxDay = 30;

    if (month === 13) {

        maxDay =
            (year % 4 === 3)
                ? 6
                : 5;
    }

    if (
        day < 1 ||
        day > maxDay
    ) {
        valid = false;
    }

    if (!valid) {

        datePreview.innerHTML =
            '<strong>Last Date:</strong> Invalid Ethiopian date.';

        return;
    }

    const monthNames = [
        '',
        'Meskerem',
        'Tikimt',
        'Hidar',
        'Tahsas',
        'Tir',
        'Yekatit',
        'Megabit',
        'Miyazya',
        'Ginbot',
        'Sene',
        'Hamle',
        'Nehase',
        'Pagume'
    ];

    datePreview.innerHTML =
        '<strong>Last Date:</strong> ' +
        day +
        ' ' +
        monthNames[month] +
        ' ' +
        year;
}

lastDateInput.addEventListener(
    'input',
    updateDatePreview
);

lastDateInput.addEventListener(
    'change',
    updateDatePreview
);

updateDatePreview();

</script>

</body>

</html>