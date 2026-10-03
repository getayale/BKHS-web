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

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

final class EthiopianCalendar
{
    private const ETHIOPIAN_EPOCH = 1723856;

    private const MONTHS_EN = [
        1  => 'Meskerem',
        2  => 'Tikimt',
        3  => 'Hidar',
        4  => 'Tahsas',
        5  => 'Tir',
        6  => 'Yekatit',
        7  => 'Megabit',
        8  => 'Miyazya',
        9  => 'Ginbot',
        10 => 'Sene',
        11 => 'Hamle',
        12 => 'Nehase',
        13 => 'Pagume',
    ];

    private const MONTHS_AM = [
        1  => 'መስከረም',
        2  => 'ጥቅምት',
        3  => 'ኅዳር',
        4  => 'ታኅሣሥ',
        5  => 'ጥር',
        6  => 'የካቲት',
        7  => 'መጋቢት',
        8  => 'ሚያዝያ',
        9  => 'ግንቦት',
        10 => 'ሰኔ',
        11 => 'ሐምሌ',
        12 => 'ነሐሴ',
        13 => 'ጳጉሜ',
    ];

    private const TIMEZONE = 'Africa/Addis_Ababa';

    public static function gregorianToEthiopian(
        int $year,
        int $month,
        int $day
    ): array {
        $date = new DateTimeImmutable(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new DateTimeZone(self::TIMEZONE)
        );

        $gregorianYear = (int) $date->format('Y');
        $gregorianMonth = (int) $date->format('m');
        $gregorianDay = (int) $date->format('d');

        $ethiopianYear =
            $gregorianYear -
            8 +
            (
                (
                    $gregorianMonth < 9 ||
                    (
                        $gregorianMonth === 9 &&
                        $gregorianDay < 11
                    )
                )
                    ? 0
                    : 1
            );

        $newYearGregorianYear =
            $ethiopianYear + 7;

        $newYearDate = self::ethiopianNewYearDate(
            $ethiopianYear
        );

        if ($date < $newYearDate) {
            $ethiopianYear--;
            $newYearDate = self::ethiopianNewYearDate(
                $ethiopianYear
            );
        }

        $difference =
            (int) $newYearDate->diff($date)->format('%r%a');

        $ethiopianDayOfYear =
            $difference + 1;

        $ethiopianMonth =
            (int) floor(
                ($ethiopianDayOfYear - 1) / 30
            ) + 1;

        $ethiopianDay =
            (($ethiopianDayOfYear - 1) % 30) + 1;

        return [
            'year' => $ethiopianYear,
            'month' => $ethiopianMonth,
            'day' => $ethiopianDay,
        ];
    }

    public static function fromGregorian(
        string $date
    ): array {
        $parsed = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new DateTimeZone(self::TIMEZONE)
        );

        if (
            $parsed === false ||
            $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException(
                'Invalid Gregorian date.'
            );
        }

        return self::gregorianToEthiopian(
            (int) $parsed->format('Y'),
            (int) $parsed->format('m'),
            (int) $parsed->format('d')
        );
    }

    public static function ethiopianToGregorian(
        int $year,
        int $month,
        int $day
    ): array {
        self::validateDate(
            $year,
            $month,
            $day
        );

        $newYearDate =
            self::ethiopianNewYearDate($year);

        $daysToAdd =
            (($month - 1) * 30) +
            ($day - 1);

        $gregorianDate =
            $newYearDate->modify(
                '+' . $daysToAdd . ' days'
            );

        return [
            'year' => (int) $gregorianDate->format('Y'),
            'month' => (int) $gregorianDate->format('m'),
            'day' => (int) $gregorianDate->format('d'),
        ];
    }

    public static function toGregorian(
        int $year,
        int $month,
        int $day
    ): string {
        $result = self::ethiopianToGregorian(
            $year,
            $month,
            $day
        );

        return sprintf(
            '%04d-%02d-%02d',
            $result['year'],
            $result['month'],
            $result['day']
        );
    }

    public static function today(): array
    {
        $today = new DateTimeImmutable(
            'now',
            new DateTimeZone(self::TIMEZONE)
        );

        return self::fromGregorian(
            $today->format('Y-m-d')
        );
    }

    public static function monthName(
        int $month
    ): string {
        return self::MONTHS_EN[$month] ?? '';
    }

    public static function monthNameAmharic(
        int $month
    ): string {
        return self::MONTHS_AM[$month] ?? '';
    }

    public static function months(): array
    {
        return self::MONTHS_EN;
    }

    public static function daysInMonth(
        int $year,
        int $month
    ): int {
        if ($month >= 1 && $month <= 12) {
            return 30;
        }

        if ($month === 13) {
            return self::isLeapYear($year)
                ? 6
                : 5;
        }

        throw new InvalidArgumentException(
            'Invalid Ethiopian month.'
        );
    }

    public static function isLeapYear(
        int $year
    ): bool {
        return $year % 4 === 3;
    }

    private static function validateDate(
        int $year,
        int $month,
        int $day
    ): void {
        if ($year < 1) {
            throw new InvalidArgumentException(
                'Invalid Ethiopian year.'
            );
        }

        if ($month < 1 || $month > 13) {
            throw new InvalidArgumentException(
                'Invalid Ethiopian month.'
            );
        }

        $daysInMonth =
            self::daysInMonth(
                $year,
                $month
            );

        if (
            $day < 1 ||
            $day > $daysInMonth
        ) {
            throw new InvalidArgumentException(
                'Invalid Ethiopian day.'
            );
        }
    }

    private static function ethiopianNewYearDate(
        int $year
    ): DateTimeImmutable {
        $gregorianYear = $year + 7;

        return new DateTimeImmutable(
            sprintf(
                '%04d-09-%02d',
                $gregorianYear,
                self::isGregorianLeapYear($gregorianYear)
                    ? 12
                    : 11
            ),
            new DateTimeZone(self::TIMEZONE)
        );
    }

    private static function isGregorianLeapYear(
        int $year
    ): bool {
        return (
            $year % 400 === 0 ||
            (
                $year % 4 === 0 &&
                $year % 100 !== 0
            )
        );
    }
}

/*
|--------------------------------------------------------------------------
| Helper
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
| CSRF TOKEN
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
| Current Ethiopian date
|--------------------------------------------------------------------------
*/

$currentEthiopian =
    EthiopianCalendar::today();

$currentEthiopianYear =
    (int) $currentEthiopian['year'];

$currentEthiopianMonth =
    (int) $currentEthiopian['month'];

$currentEthiopianDay =
    (int) $currentEthiopian['day'];

/*
|--------------------------------------------------------------------------
| Default form values
|--------------------------------------------------------------------------
*/

$title = '';
$content = '';

$status = 'Draft';

/*
|--------------------------------------------------------------------------
| Expiration date
|--------------------------------------------------------------------------
|
| These are Ethiopian-calendar values used ONLY by the UI.
|
*/

$lastDateYear =
    $currentEthiopianYear;

$lastDateMonth =
    $currentEthiopianMonth;

$lastDateDay =
    $currentEthiopianDay;

$lastDateProvided = false;

/*
|--------------------------------------------------------------------------
| Audience defaults
|--------------------------------------------------------------------------
*/

$publicSelected = false;

$studentSelected = false;
$studentAllGrades = false;
$studentGrades = [];

$parentSelected = false;
$parentAllGrades = false;
$parentGrades = [];

$teacherSelected = false;

$errorMessage = '';

/*
|--------------------------------------------------------------------------
| Principal profile
|--------------------------------------------------------------------------
*/

$principalStmt = $conn->prepare(
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

if (!$principalStmt) {
    die('Unable to prepare principal query.');
}

$principalUserId =
    (int) $_SESSION['user_id'];

$principalStmt->bind_param(
    'i',
    $principalUserId
);

$principalStmt->execute();

$principalResult =
    $principalStmt->get_result();

$principal =
    $principalResult->fetch_assoc();

$principalStmt->close();

if (!$principal) {

    session_destroy();

    header(
        'Location: ../../auth/login.php'
    );

    exit;
}

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
        ltrim(
            $principalPhoto,
            '/'
        );
}

/*
|--------------------------------------------------------------------------
| Handle POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    $postedCsrfToken =
        (string) (
            $_POST['csrf_token'] ?? ''
        );

    if (
        $postedCsrfToken === '' ||
        !hash_equals(
            (string) $_SESSION['announcement_csrf'],
            $postedCsrfToken
        )
    ) {

        $errorMessage =
            'Invalid security token. Please refresh the page and try again.';
    }

    /*
    |--------------------------------------------------------------------------
    | Read form values
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

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

        /*
        |--------------------------------------------------------------------------
        | Ethiopian expiration date
        |--------------------------------------------------------------------------
        */

        $lastDateYear =
            (int) (
                $_POST['last_date_year'] ??
                $currentEthiopianYear
            );

        $lastDateMonth =
            (int) (
                $_POST['last_date_month'] ??
                $currentEthiopianMonth
            );

        $lastDateDay =
            (int) (
                $_POST['last_date_day'] ??
                $currentEthiopianDay
            );

        $lastDateProvided =
            isset($_POST['last_date_enabled']) &&
            (string) $_POST['last_date_enabled'] === '1';

        /*
        |--------------------------------------------------------------------------
        | Audience
        |--------------------------------------------------------------------------
        */

        $audiences =
            $_POST['audiences'] ?? [];

        if (!is_array($audiences)) {
            $audiences = [];
        }

        $publicSelected =
            in_array(
                'Public',
                $audiences,
                true
            );

        $studentSelected =
            in_array(
                'Student',
                $audiences,
                true
            );

        $parentSelected =
            in_array(
                'Parent',
                $audiences,
                true
            );

        $teacherSelected =
            in_array(
                'Teacher',
                $audiences,
                true
            );

        $studentAllGrades =
            isset($_POST['student_all_grades']) &&
            (string) $_POST['student_all_grades'] === '1';

        $parentAllGrades =
            isset($_POST['parent_all_grades']) &&
            (string) $_POST['parent_all_grades'] === '1';

        $studentGrades =
            $_POST['student_grades'] ?? [];

        $parentGrades =
            $_POST['parent_grades'] ?? [];

        if (!is_array($studentGrades)) {
            $studentGrades = [];
        }

        if (!is_array($parentGrades)) {
            $parentGrades = [];
        }

        /*
        |--------------------------------------------------------------------------
        | Validate title
        |--------------------------------------------------------------------------
        */

        if ($title === '') {

            $errorMessage =
                'Please enter an announcement title.';

        } elseif (mb_strlen($title) > 255) {

            $errorMessage =
                'The announcement title cannot exceed 255 characters.';
        }

        /*
        |--------------------------------------------------------------------------
        | Validate status
        |--------------------------------------------------------------------------
        */

        if ($errorMessage === '') {

            if (
                !in_array(
                    $status,
                    ['Draft', 'Published'],
                    true
                )
            ) {
                $status = 'Draft';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate expiration date
        |--------------------------------------------------------------------------
        */

        if ($errorMessage === '') {

            if (
                $status === 'Published' &&
                !$lastDateProvided
            ) {

                $errorMessage =
                    'Please select the last date for a published announcement.';
            }
        }

        if (
            $errorMessage === '' &&
            $lastDateProvided
        ) {

            try {

                EthiopianCalendar::toGregorian(
                    $lastDateYear,
                    $lastDateMonth,
                    $lastDateDay
                );

                /*
                |--------------------------------------------------------------------------
                | Expiration cannot be before today
                |--------------------------------------------------------------------------
                */

                $selectedGregorian =
                    EthiopianCalendar::toGregorian(
                        $lastDateYear,
                        $lastDateMonth,
                        $lastDateDay
                    );

                $todayGregorian =
                    date('Y-m-d');

                if (
                    $selectedGregorian <
                    $todayGregorian
                ) {

                    $errorMessage =
                        'The last date cannot be before today.';
                }

            } catch (Throwable $exception) {

                $errorMessage =
                    'Please select a valid Ethiopian last date.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Validate audience
        |--------------------------------------------------------------------------
        */

        if ($errorMessage === '') {

            if (
                !$publicSelected &&
                !$studentSelected &&
                !$parentSelected &&
                !$teacherSelected
            ) {

                $errorMessage =
                    'Please select at least one audience.';
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Normalize grades
        |--------------------------------------------------------------------------
        */

        if ($errorMessage === '') {

            $studentGrades =
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                'intval',
                                $studentGrades
                            ),
                            static function (
                                int $grade
                            ): bool {
                                return
                                    $grade >= 1 &&
                                    $grade <= 12;
                            }
                        )
                    )
                );

            $parentGrades =
                array_values(
                    array_unique(
                        array_filter(
                            array_map(
                                'intval',
                                $parentGrades
                            ),
                            static function (
                                int $grade
                            ): bool {
                                return
                                    $grade >= 1 &&
                                    $grade <= 12;
                            }
                        )
                    )
                );

            /*
            |--------------------------------------------------------------------------
            | Student grades
            |--------------------------------------------------------------------------
            */

            if (
                $studentSelected &&
                !$studentAllGrades &&
                count($studentGrades) === 0
            ) {

                $errorMessage =
                    'Please select All Grades or at least one student grade.';
            }

            /*
            |--------------------------------------------------------------------------
            | Parent grades
            |--------------------------------------------------------------------------
            */

            if (
                $errorMessage === '' &&
                $parentSelected &&
                !$parentAllGrades &&
                count($parentGrades) === 0
            ) {

                $errorMessage =
                    'Please select All Grades or at least one parent grade.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Uploaded files
    |--------------------------------------------------------------------------
    */

    $imageFiles = [];
    $attachmentFiles = [];

    if ($errorMessage === '') {

        /*
        |--------------------------------------------------------------------------
        | Images
        |--------------------------------------------------------------------------
        */

        if (
            isset($_FILES['images']) &&
            is_array(
                $_FILES['images']['name'] ?? null
            )
        ) {

            $imageNames =
                $_FILES['images']['name'];

            $imageTmpNames =
                $_FILES['images']['tmp_name'];

            $imageErrors =
                $_FILES['images']['error'];

            $imageSizes =
                $_FILES['images']['size'];

            $imageCount =
                count($imageNames);

            if ($imageCount > 10) {

                $errorMessage =
                    'You can upload a maximum of 10 images.';

            } else {

                $allowedExtensions = [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp'
                ];

                $allowedMimeTypes = [
                    'image/jpeg',
                    'image/png',
                    'image/webp'
                ];

                for (
                    $i = 0;
                    $i < $imageCount;
                    $i++
                ) {

                    if (
                        empty($imageNames[$i]) ||
                        (
                            $imageErrors[$i]
                            ?? UPLOAD_ERR_NO_FILE
                        ) === UPLOAD_ERR_NO_FILE
                    ) {
                        continue;
                    }

                    if (
                        (
                            $imageErrors[$i]
                            ?? UPLOAD_ERR_OK
                        ) !== UPLOAD_ERR_OK
                    ) {

                        $errorMessage =
                            'One of the image files could not be uploaded.';

                        break;
                    }

                    $originalName =
                        basename(
                            (string) $imageNames[$i]
                        );

                    $extension =
                        strtolower(
                            pathinfo(
                                $originalName,
                                PATHINFO_EXTENSION
                            )
                        );

                    $size =
                        (int) (
                            $imageSizes[$i] ?? 0
                        );

                    if (
                        !in_array(
                            $extension,
                            $allowedExtensions,
                            true
                        )
                    ) {

                        $errorMessage =
                            'Only JPG, JPEG, PNG, and WEBP images are allowed.';

                        break;
                    }

                    if (
                        $size >
                        5 * 1024 * 1024
                    ) {

                        $errorMessage =
                            'Each image must be 5 MB or smaller.';

                        break;
                    }

                    $tmpName =
                        (string) (
                            $imageTmpNames[$i] ?? ''
                        );

                    if (
                        !is_uploaded_file(
                            $tmpName
                        )
                    ) {

                        $errorMessage =
                            'Invalid image upload detected.';

                        break;
                    }

                    $imageInfo =
                        @getimagesize(
                            $tmpName
                        );

                    if ($imageInfo === false) {

                        $errorMessage =
                            'One of the uploaded files is not a valid image.';

                        break;
                    }

                    $mimeType =
                        (string) (
                            $imageInfo['mime'] ?? ''
                        );

                    if (
                        !in_array(
                            $mimeType,
                            $allowedMimeTypes,
                            true
                        )
                    ) {

                        $errorMessage =
                            'One of the uploaded images has an invalid image type.';

                        break;
                    }

                    $imageFiles[] = [
                        'original_name' => $originalName,
                        'tmp_name' => $tmpName,
                        'extension' => $extension,
                        'mime_type' => $mimeType,
                        'size' => $size
                    ];
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Attachments
        |--------------------------------------------------------------------------
        */

        if (
            $errorMessage === '' &&
            isset($_FILES['attachments']) &&
            is_array(
                $_FILES['attachments']['name'] ?? null
            )
        ) {

            $attachmentNames =
                $_FILES['attachments']['name'];

            $attachmentTmpNames =
                $_FILES['attachments']['tmp_name'];

            $attachmentErrors =
                $_FILES['attachments']['error'];

            $attachmentSizes =
                $_FILES['attachments']['size'];

            $attachmentTypes =
                $_FILES['attachments']['type'];

            $attachmentCount =
                count($attachmentNames);

            if ($attachmentCount > 10) {

                $errorMessage =
                    'You can upload a maximum of 10 attachments.';

            } else {

                $allowedExtensions = [
                    'pdf',
                    'doc',
                    'docx',
                    'xls',
                    'xlsx',
                    'ppt',
                    'pptx',
                    'txt',
                    'zip'
                ];

                for (
                    $i = 0;
                    $i < $attachmentCount;
                    $i++
                ) {

                    if (
                        empty($attachmentNames[$i]) ||
                        (
                            $attachmentErrors[$i]
                            ?? UPLOAD_ERR_NO_FILE
                        ) === UPLOAD_ERR_NO_FILE
                    ) {
                        continue;
                    }

                    if (
                        (
                            $attachmentErrors[$i]
                            ?? UPLOAD_ERR_OK
                        ) !== UPLOAD_ERR_OK
                    ) {

                        $errorMessage =
                            'One of the attachment files could not be uploaded.';

                        break;
                    }

                    $originalName =
                        basename(
                            (string) $attachmentNames[$i]
                        );

                    $extension =
                        strtolower(
                            pathinfo(
                                $originalName,
                                PATHINFO_EXTENSION
                            )
                        );

                    $size =
                        (int) (
                            $attachmentSizes[$i] ?? 0
                        );

                    if (
                        !in_array(
                            $extension,
                            $allowedExtensions,
                            true
                        )
                    ) {

                        $errorMessage =
                            'Invalid attachment type.';

                        break;
                    }

                    if (
                        $size >
                        10 * 1024 * 1024
                    ) {

                        $errorMessage =
                            'Each attachment must be 10 MB or smaller.';

                        break;
                    }

                    $tmpName =
                        (string) (
                            $attachmentTmpNames[$i] ?? ''
                        );

                    if (
                        !is_uploaded_file(
                            $tmpName
                        )
                    ) {

                        $errorMessage =
                            'Invalid attachment upload detected.';

                        break;
                    }

                    $mimeType =
                        (string) (
                            $attachmentTypes[$i] ?? ''
                        );

                    $attachmentFiles[] = [
                        'original_name' => $originalName,
                        'tmp_name' => $tmpName,
                        'extension' => $extension,
                        'mime_type' => $mimeType,
                        'size' => $size
                    ];
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Create upload directories
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

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

                $errorMessage =
                    'Unable to create the announcement image directory.';
            }
        }

        if (
            $errorMessage === '' &&
            !is_dir($attachmentDirectory)
        ) {

            if (
                !mkdir(
                    $attachmentDirectory,
                    0755,
                    true
                ) &&
                !is_dir($attachmentDirectory)
            ) {

                $errorMessage =
                    'Unable to create the announcement attachment directory.';
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Database transaction
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

        $createdFiles = [];

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Dates
            |--------------------------------------------------------------------------
            */

            $publishedAt = null;
            $closedAt = null;

            if ($status === 'Published') {

                $publishedAt =
                    date('Y-m-d H:i:s');
            }

            /*
            |--------------------------------------------------------------------------
            | Convert Ethiopian last date to Gregorian
            |--------------------------------------------------------------------------
            */

            if ($lastDateProvided) {

                $closedGregorian =
                    EthiopianCalendar::toGregorian(
                        $lastDateYear,
                        $lastDateMonth,
                        $lastDateDay
                    );

                $closedAt =
                    $closedGregorian .
                    ' 23:59:59';
            }

            /*
            |--------------------------------------------------------------------------
            | Insert announcement
            |--------------------------------------------------------------------------
            */

            $announcementStmt =
                $conn->prepare(
                    "INSERT INTO announcements
                    (
                        title,
                        content,
                        status,
                        created_by,
                        published_at,
                        closed_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?)"
                );

            if (!$announcementStmt) {

                throw new RuntimeException(
                    'Unable to prepare announcement insert.'
                );
            }

            /*
             * 6 variables
             *
             * s = title
             * s = content
             * s = status
             * i = created_by
             * s = published_at
             * s = closed_at
             */

            $announcementStmt->bind_param(
                'sssiss',
                $title,
                $content,
                $status,
                $principalUserId,
                $publishedAt,
                $closedAt
            );

            if (
                !$announcementStmt->execute()
            ) {

                throw new RuntimeException(
                    'Unable to create announcement: ' .
                    $announcementStmt->error
                );
            }

            $announcementId =
                (int) $conn->insert_id;

            $announcementStmt->close();

            /*
            |--------------------------------------------------------------------------
            | Audience insert
            |--------------------------------------------------------------------------
            */

            $audienceStmt =
                $conn->prepare(
                    "INSERT INTO announcement_audiences
                    (
                        announcement_id,
                        audience_type,
                        grade
                    )
                    VALUES (?, ?, ?)"
                );

            if (!$audienceStmt) {

                throw new RuntimeException(
                    'Unable to prepare audience insert.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Public
            |--------------------------------------------------------------------------
            */

            if ($publicSelected) {

                $audienceType =
                    'Public';

                $grade = null;

                $audienceStmt->bind_param(
                    'isi',
                    $announcementId,
                    $audienceType,
                    $grade
                );

                if (
                    !$audienceStmt->execute()
                ) {

                    throw new RuntimeException(
                        'Unable to save Public audience: ' .
                        $audienceStmt->error
                    );
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Students
            |--------------------------------------------------------------------------
            */

            if ($studentSelected) {

                $audienceType =
                    'Student';

                if ($studentAllGrades) {

                    $grade = null;

                    $audienceStmt->bind_param(
                        'isi',
                        $announcementId,
                        $audienceType,
                        $grade
                    );

                    if (
                        !$audienceStmt->execute()
                    ) {

                        throw new RuntimeException(
                            'Unable to save Student audience: ' .
                            $audienceStmt->error
                        );
                    }

                } else {

                    foreach (
                        $studentGrades
                        as $selectedGrade
                    ) {

                        $grade =
                            (int) $selectedGrade;

                        $audienceStmt->bind_param(
                            'isi',
                            $announcementId,
                            $audienceType,
                            $grade
                        );

                        if (
                            !$audienceStmt->execute()
                        ) {

                            throw new RuntimeException(
                                'Unable to save Student grade audience: ' .
                                $audienceStmt->error
                            );
                        }
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Parents
            |--------------------------------------------------------------------------
            */

            if ($parentSelected) {

                $audienceType =
                    'Parent';

                if ($parentAllGrades) {

                    $grade = null;

                    $audienceStmt->bind_param(
                        'isi',
                        $announcementId,
                        $audienceType,
                        $grade
                    );

                    if (
                        !$audienceStmt->execute()
                    ) {

                        throw new RuntimeException(
                            'Unable to save Parent audience: ' .
                            $audienceStmt->error
                        );
                    }

                } else {

                    foreach (
                        $parentGrades
                        as $selectedGrade
                    ) {

                        $grade =
                            (int) $selectedGrade;

                        $audienceStmt->bind_param(
                            'isi',
                            $announcementId,
                            $audienceType,
                            $grade
                        );

                        if (
                            !$audienceStmt->execute()
                        ) {

                            throw new RuntimeException(
                                'Unable to save Parent grade audience: ' .
                                $audienceStmt->error
                            );
                        }
                    }
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Teachers
            |--------------------------------------------------------------------------
            */

            if ($teacherSelected) {

                $audienceType =
                    'Teacher';

                $grade = null;

                $audienceStmt->bind_param(
                    'isi',
                    $announcementId,
                    $audienceType,
                    $grade
                );

                if (
                    !$audienceStmt->execute()
                ) {

                    throw new RuntimeException(
                        'Unable to save Teacher audience: ' .
                        $audienceStmt->error
                    );
                }
            }

            $audienceStmt->close();

            /*
            |--------------------------------------------------------------------------
            | Media statement
            |--------------------------------------------------------------------------
            */

            $mediaStmt =
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

            if (!$mediaStmt) {

                throw new RuntimeException(
                    'Unable to prepare media insert.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Image files
            |--------------------------------------------------------------------------
            */

            $displayOrder = 0;

            foreach (
                $imageFiles
                as $file
            ) {

                $newFileName =
                    bin2hex(
                        random_bytes(16)
                    ) .
                    '_' .
                    time() .
                    '.' .
                    $file['extension'];

                $destination =
                    $imageDirectory .
                    $newFileName;

                if (
                    !move_uploaded_file(
                        $file['tmp_name'],
                        $destination
                    )
                ) {

                    throw new RuntimeException(
                        'Unable to save uploaded image: ' .
                        $file['original_name']
                    );
                }

                $createdFiles[] =
                    $destination;

                $mediaType =
                    'Image';

                $filePath =
                    'uploads/announcements/images/' .
                    $newFileName;

                $originalName =
                    $file['original_name'];

                $mimeType =
                    $file['mime_type'];

                $fileSize =
                    (int) $file['size'];

                $mediaStmt->bind_param(
                    'issssii',
                    $announcementId,
                    $mediaType,
                    $filePath,
                    $originalName,
                    $mimeType,
                    $fileSize,
                    $displayOrder
                );

                if (
                    !$mediaStmt->execute()
                ) {

                    throw new RuntimeException(
                        'Unable to save image information: ' .
                        $mediaStmt->error
                    );
                }

                $mediaId =
                    (int) $conn->insert_id;

                /*
                |--------------------------------------------------------------------------
                | Image content block
                |--------------------------------------------------------------------------
                */

                $blockStmt =
                    $conn->prepare(
                        "INSERT INTO announcement_content_blocks
                        (
                            announcement_id,
                            block_type,
                            content,
                            media_id,
                            display_order
                        )
                        VALUES (?, ?, ?, ?, ?)"
                    );

                if (!$blockStmt) {

                    throw new RuntimeException(
                        'Unable to prepare image content block.'
                    );
                }

                $blockType =
                    'Image';

                $blockContent = null;

                $blockDisplayOrder =
                    $displayOrder + 1;

                $blockStmt->bind_param(
                    'issii',
                    $announcementId,
                    $blockType,
                    $blockContent,
                    $mediaId,
                    $blockDisplayOrder
                );

                if (
                    !$blockStmt->execute()
                ) {

                    throw new RuntimeException(
                        'Unable to save image content block: ' .
                        $blockStmt->error
                    );
                }

                $blockStmt->close();

                $displayOrder++;
            }

            /*
            |--------------------------------------------------------------------------
            | Attachment files
            |--------------------------------------------------------------------------
            */

            foreach (
                $attachmentFiles
                as $file
            ) {

                $newFileName =
                    bin2hex(
                        random_bytes(16)
                    ) .
                    '_' .
                    time() .
                    '.' .
                    $file['extension'];

                $destination =
                    $attachmentDirectory .
                    $newFileName;

                if (
                    !move_uploaded_file(
                        $file['tmp_name'],
                        $destination
                    )
                ) {

                    throw new RuntimeException(
                        'Unable to save uploaded attachment: ' .
                        $file['original_name']
                    );
                }

                $createdFiles[] =
                    $destination;

                $mediaType =
                    'Attachment';

                $filePath =
                    'uploads/announcements/attachments/' .
                    $newFileName;

                $originalName =
                    $file['original_name'];

                $mimeType =
                    $file['mime_type'];

                $fileSize =
                    (int) $file['size'];

                $mediaStmt->bind_param(
                    'issssii',
                    $announcementId,
                    $mediaType,
                    $filePath,
                    $originalName,
                    $mimeType,
                    $fileSize,
                    $displayOrder
                );

                if (
                    !$mediaStmt->execute()
                ) {

                    throw new RuntimeException(
                        'Unable to save attachment information: ' .
                        $mediaStmt->error
                    );
                }

                $displayOrder++;
            }

            $mediaStmt->close();

            /*
            |--------------------------------------------------------------------------
            | Main Text Content Block
            |--------------------------------------------------------------------------
            */

            if ($content !== '') {

                $blockStmt =
                    $conn->prepare(
                        "INSERT INTO announcement_content_blocks
                        (
                            announcement_id,
                            block_type,
                            content,
                            media_id,
                            display_order
                        )
                        VALUES (?, ?, ?, ?, ?)"
                    );

                if (!$blockStmt) {

                    throw new RuntimeException(
                        'Unable to prepare text content block.'
                    );
                }

                $blockType =
                    'Text';

                $mediaId = null;

                $blockDisplayOrder = 0;

                $blockStmt->bind_param(
                    'issii',
                    $announcementId,
                    $blockType,
                    $content,
                    $mediaId,
                    $blockDisplayOrder
                );

                if (
                    !$blockStmt->execute()
                ) {

                    throw new RuntimeException(
                        'Unable to save text content block: ' .
                        $blockStmt->error
                    );
                }

                $blockStmt->close();
            }

            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $conn->commit();

            $_SESSION['announcement_success'] =
                $status === 'Published'
                    ? 'Announcement published successfully.'
                    : 'Announcement saved as draft successfully.';

            header(
                'Location: ../announcements.php'
            );

            exit;

        } catch (Throwable $exception) {

            $conn->rollback();

            foreach (
                $createdFiles
                as $createdFile
            ) {

                if (is_file($createdFile)) {
                    @unlink($createdFile);
                }
            }

            $errorMessage =
                $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Create Announcement | Principal</title>
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
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Inter', sans-serif;
            background: var(--background);
            color: var(--text);
        }

        a {
            text-decoration: none;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            z-index: 1100;
            overflow-y: auto;
            transition: transform .3s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            color: #fff;
            font-size: 20px;
            font-weight: 800;
            border-bottom: 1px solid rgba(255,255,255,.07);
        }

        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 11px;
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .nav-label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            padding: 12px 12px 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #9ca3af;
            padding: 12px 13px;
            border-radius: 10px;
            margin-bottom: 4px;
            font-size: 14px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
            transform: translateX(2px);
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        .sidebar-link.logout {
            margin-top: 18px;
            color: #fca5a5;
        }

        .sidebar-link.logout:hover {
            background: rgba(220,38,38,.12);
            color: #fecaca;
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

        .menu-toggle {
            display: none;
            border: 0;
            background: transparent;
            font-size: 25px;
            color: var(--text);
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle {
            margin: 3px 0 0;
            font-size: 12px;
            color: var(--muted);
        }

        .principal-profile {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .principal-info {
            text-align: right;
        }

        .principal-name {
            font-size: 13px;
            font-weight: 700;
        }

        .principal-role {
            font-size: 11px;
            color: var(--muted);
        }

        .principal-avatar {
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

        .principal-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content {
            padding: 30px;
            max-width: 1250px;
            margin: 0 auto;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
        }

        .back-link:hover {
            color: var(--primary);
        }

        .form-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 3px 12px rgba(15,23,42,.035);
            margin-bottom: 22px;
            overflow: hidden;
        }

        .card-header-custom {
            padding: 20px 22px;
            border-bottom: 1px solid var(--border);
        }

        .card-header-custom h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
        }

        .card-header-custom p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .card-body-custom {
            padding: 22px;
        }

        .form-label {
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .form-control,
        .form-select {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 11px 13px;
            font-size: 13px;
            box-shadow: none !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79,70,229,.08) !important;
        }

        textarea.form-control {
            min-height: 220px;
            resize: vertical;
        }

        .audience-box {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .audience-main {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .audience-main label {
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        .audience-options {
            margin-top: 14px;
            padding-left: 30px;
        }

        .form-check {
            margin-bottom: 7px;
        }

        .form-check-label {
            font-size: 13px;
            cursor: pointer;
        }

        .grades-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 7px;
            margin-top: 10px;
        }

        .grade-check {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 7px 9px;
            background: #fff;
        }

        .grade-check .form-check {
            margin: 0;
        }

        .upload-area {
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            padding: 22px;
            text-align: center;
            background: #fafafa;
        }

        .upload-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 10px;
            border-radius: 12px;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 21px;
        }

        .upload-title {
            font-size: 13px;
            font-weight: 700;
        }

        .upload-help {
            font-size: 11px;
            color: var(--muted);
            margin-top: 4px;
        }

        /*
        |--------------------------------------------------------------------------
        | Ethiopian date picker
        |--------------------------------------------------------------------------
        */

        .ethiopian-date-box {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            background: #fafafa;
        }

        .ethiopian-date-row {
            display: grid;
            grid-template-columns: 1.1fr 1.4fr 1fr;
            gap: 10px;
        }

        .ethiopian-date-preview {
            margin-top: 12px;
            padding: 11px 13px;
            background: #eef2ff;
            border-radius: 10px;
            color: var(--primary-dark);
            font-size: 12px;
            font-weight: 600;
        }

        .date-help {
            color: var(--muted);
            font-size: 11px;
            margin-top: 7px;
        }

        .status-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .status-option {
            position: relative;
        }

        .status-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .status-option label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 17px;
            border: 1px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all .2s ease;
        }

        .status-option label:hover {
            border-color: var(--primary);
        }

        .status-option input:checked + label {
            background: #eef2ff;
            border-color: var(--primary);
            color: var(--primary);
        }

        .actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
        }

        .btn-custom {
            border-radius: 10px;
            padding: 11px 18px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-cancel {
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-primary-custom {
            background: var(--primary);
            color: #fff;
            border: 1px solid var(--primary);
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #fff;
        }

        .alert {
            border-radius: 12px;
            font-size: 13px;
        }

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 1050;
        }

        @media (max-width: 991px) {

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

            .menu-toggle {
                display: block;
            }

            .topbar {
                padding: 0 18px;
            }

            .content {
                padding: 22px 18px;
            }
        }

        @media (max-width: 767px) {

            .principal-info {
                display: none;
            }

            .page-title {
                font-size: 17px;
            }

            .grades-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .card-body-custom {
                padding: 17px;
            }

            .card-header-custom {
                padding: 17px;
            }

            .actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .actions .btn {
                width: 100%;
            }

            .ethiopian-date-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 450px) {

            .grades-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .content {
                padding: 17px 12px;
            }
        }

    </style>

</head>

<body>

<div class="overlay" id="overlay"></div>

<aside class="sidebar" id="sidebar">

    <div class="sidebar-brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        BKHS

    </div>

    <nav class="sidebar-nav">

        <div class="nav-label">
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
            <span>Announcements</span>
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

        <div class="nav-label">
            Account
        </div>

        <a
            href="../profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../../auth/logout.php"
            class="sidebar-link logout"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<main class="main">

    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="menu-toggle"
                id="menuToggle"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>

                <h1 class="page-title">
                    Create Announcement
                </h1>

                <p class="page-subtitle">
                    Create and publish an announcement for the school community
                </p>

            </div>

        </div>

        <div class="principal-profile">

            <div class="principal-info">

                <div class="principal-name">
                    <?= e($principal['full_name'] ?? 'Principal') ?>
                </div>

                <div class="principal-role">
                    Principal
                </div>

            </div>

            <div class="principal-avatar">

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

    <section class="content">

        <a
            href="../announcements.php"
            class="back-link"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Announcements
        </a>

        <?php if ($errorMessage !== ''): ?>

            <div class="alert alert-danger mb-4">

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                <?= e($errorMessage) ?>

            </div>

        <?php endif; ?>

        <form
            method="POST"
            enctype="multipart/form-data"
            id="announcementForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($csrfToken) ?>"
            >

            <!-- Announcement Information -->

            <div class="form-card">

                <div class="card-header-custom">

                    <h2>
                        Announcement Information
                    </h2>

                    <p>
                        Add the title and main content of your announcement.
                    </p>

                </div>

                <div class="card-body-custom">

                    <div class="mb-4">

                        <label
                            for="title"
                            class="form-label"
                        >
                            Title
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            id="title"
                            name="title"
                            class="form-control"
                            maxlength="255"
                            value="<?= e($title) ?>"
                            placeholder="Enter announcement title"
                            required
                        >

                    </div>

                    <div>

                        <label
                            for="content"
                            class="form-label"
                        >
                            Content
                        </label>

                        <textarea
                            id="content"
                            name="content"
                            class="form-control"
                            placeholder="Write your announcement here..."
                        ><?= e($content) ?></textarea>

                    </div>

                </div>

            </div>

            <!-- Audience -->

            <div class="form-card">

                <div class="card-header-custom">

                    <h2>
                        Audience
                    </h2>

                    <p>
                        Choose who should receive this announcement.
                    </p>

                </div>

                <div class="card-body-custom">

                    <!-- Public -->

                    <div class="audience-box">

                        <div class="audience-main">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="audiences[]"
                                value="Public"
                                id="audiencePublic"
                                <?= $publicSelected ? 'checked' : '' ?>
                            >

                            <label for="audiencePublic">
                                Public
                            </label>

                        </div>

                    </div>

                    <!-- Students -->

                    <div class="audience-box">

                        <div class="audience-main">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="audiences[]"
                                value="Student"
                                id="audienceStudent"
                                <?= $studentSelected ? 'checked' : '' ?>
                            >

                            <label for="audienceStudent">
                                Students
                            </label>

                        </div>

                        <div
                            class="audience-options"
                            id="studentOptions"
                            style="<?= $studentSelected ? '' : 'display:none;' ?>"
                        >

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="student_all_grades"
                                    value="1"
                                    id="studentAllGrades"
                                    <?= $studentAllGrades ? 'checked' : '' ?>
                                >

                                <label
                                    class="form-check-label fw-semibold"
                                    for="studentAllGrades"
                                >
                                    All Grades
                                </label>

                            </div>

                            <div class="grades-grid">

                                <?php for ($grade = 1; $grade <= 12; $grade++): ?>

                                    <div class="grade-check">

                                        <div class="form-check">

                                            <input
                                                class="form-check-input student-grade"
                                                type="checkbox"
                                                name="student_grades[]"
                                                value="<?= $grade ?>"
                                                id="studentGrade<?= $grade ?>"
                                                <?= in_array($grade, $studentGrades, true) ? 'checked' : '' ?>
                                            >

                                            <label
                                                class="form-check-label"
                                                for="studentGrade<?= $grade ?>"
                                            >
                                                Grade <?= $grade ?>
                                            </label>

                                        </div>

                                    </div>

                                <?php endfor; ?>

                            </div>

                        </div>

                    </div>

                    <!-- Parents -->

                    <div class="audience-box">

                        <div class="audience-main">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="audiences[]"
                                value="Parent"
                                id="audienceParent"
                                <?= $parentSelected ? 'checked' : '' ?>
                            >

                            <label for="audienceParent">
                                Parents
                            </label>

                        </div>

                        <div
                            class="audience-options"
                            id="parentOptions"
                            style="<?= $parentSelected ? '' : 'display:none;' ?>"
                        >

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="parent_all_grades"
                                    value="1"
                                    id="parentAllGrades"
                                    <?= $parentAllGrades ? 'checked' : '' ?>
                                >

                                <label
                                    class="form-check-label fw-semibold"
                                    for="parentAllGrades"
                                >
                                    All Grades
                                </label>

                            </div>

                            <div class="grades-grid">

                                <?php for ($grade = 1; $grade <= 12; $grade++): ?>

                                    <div class="grade-check">

                                        <div class="form-check">

                                            <input
                                                class="form-check-input parent-grade"
                                                type="checkbox"
                                                name="parent_grades[]"
                                                value="<?= $grade ?>"
                                                id="parentGrade<?= $grade ?>"
                                                <?= in_array($grade, $parentGrades, true) ? 'checked' : '' ?>
                                            >

                                            <label
                                                class="form-check-label"
                                                for="parentGrade<?= $grade ?>"
                                            >
                                                Grade <?= $grade ?>
                                            </label>

                                        </div>

                                    </div>

                                <?php endfor; ?>

                            </div>

                        </div>

                    </div>

                    <!-- Teachers -->

                    <div class="audience-box">

                        <div class="audience-main">

                            <input
                                class="form-check-input"
                                type="checkbox"
                                name="audiences[]"
                                value="Teacher"
                                id="audienceTeacher"
                                <?= $teacherSelected ? 'checked' : '' ?>
                            >

                            <label for="audienceTeacher">
                                Teachers
                            </label>

                        </div>

                        <div class="audience-options">

                            <div class="form-check">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    checked
                                    disabled
                                >

                                <label class="form-check-label">
                                    All Teachers
                                </label>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Media -->

            <div class="form-card">

                <div class="card-header-custom">

                    <h2>
                        Media & Attachments
                    </h2>

                    <p>
                        Add images or supporting documents.
                    </p>

                </div>

                <div class="card-body-custom">

                    <div class="row g-4">

                        <div class="col-lg-6">

                            <label class="form-label">
                                Images
                            </label>

                            <div class="upload-area">

                                <div class="upload-icon">
                                    <i class="bi bi-images"></i>
                                </div>

                                <div class="upload-title">
                                    Upload announcement images
                                </div>

                                <div class="upload-help">
                                    JPG, JPEG, PNG or WEBP · Maximum 5 MB each · Up to 10 images
                                </div>

                                <input
                                    type="file"
                                    name="images[]"
                                    class="form-control mt-3"
                                    accept=".jpg,.jpeg,.png,.webp"
                                    multiple
                                >

                            </div>

                        </div>

                        <div class="col-lg-6">

                            <label class="form-label">
                                Attachments
                            </label>

                            <div class="upload-area">

                                <div class="upload-icon">
                                    <i class="bi bi-paperclip"></i>
                                </div>

                                <div class="upload-title">
                                    Upload supporting files
                                </div>

                                <div class="upload-help">
                                    PDF, Word, Excel, PowerPoint, TXT or ZIP · Maximum 10 MB each
                                </div>

                                <input
                                    type="file"
                                    name="attachments[]"
                                    class="form-control mt-3"
                                    accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
                                    multiple
                                >

                            </div>

                        </div>

                    </div>

                </div>

            </div>

            <!-- Publication -->

            <div class="form-card">

                <div class="card-header-custom">

                    <h2>
                        Publication
                    </h2>

                    <p>
                        Choose when the announcement is published and when it stops being visible.
                    </p>

                </div>

                <div class="card-body-custom">

                    <div class="status-options">

                        <div class="status-option">

                            <input
                                type="radio"
                                name="status"
                                value="Draft"
                                id="statusDraft"
                                <?= $status === 'Draft' ? 'checked' : '' ?>
                            >

                            <label for="statusDraft">
                                <i class="bi bi-file-earmark-text"></i>
                                Save as Draft
                            </label>

                        </div>

                        <div class="status-option">

                            <input
                                type="radio"
                                name="status"
                                value="Published"
                                id="statusPublished"
                                <?= $status === 'Published' ? 'checked' : '' ?>
                            >

                            <label for="statusPublished">
                                <i class="bi bi-megaphone-fill"></i>
                                Publish Now
                            </label>

                        </div>

                    </div>

                    <!-- Ethiopian Last Date -->

                    <div class="mt-4">

                        <label class="form-label">
                            Last Date
                            <span
                                class="text-danger"
                                id="lastDateRequired"
                                style="<?= $status === 'Published' ? '' : 'display:none;' ?>"
                            >
                                *
                            </span>
                        </label>

                        <div class="ethiopian-date-box">

                            <div class="form-check mb-3">

                                <input
                                    class="form-check-input"
                                    type="checkbox"
                                    name="last_date_enabled"
                                    value="1"
                                    id="lastDateEnabled"
                                    <?= $lastDateProvided ? 'checked' : '' ?>
                                >

                                <label
                                    class="form-check-label fw-semibold"
                                    for="lastDateEnabled"
                                >
                                    Set an expiration date
                                </label>

                            </div>

                            <div
                                id="lastDateFields"
                                style="<?= $lastDateProvided ? '' : 'display:none;' ?>"
                            >

                                <div class="ethiopian-date-row">

                                    <div>

                                        <label
                                            for="lastDateYear"
                                            class="form-label"
                                        >
                                            Year
                                        </label>

                                        <select
                                            name="last_date_year"
                                            id="lastDateYear"
                                            class="form-select"
                                        >

                                            <?php
                                            for (
                                                $year =
                                                    $currentEthiopianYear;
                                                $year <=
                                                    $currentEthiopianYear + 10;
                                                $year++
                                            ):
                                            ?>

                                                <option
                                                    value="<?= $year ?>"
                                                    <?= $year === $lastDateYear ? 'selected' : '' ?>
                                                >
                                                    <?= $year ?>
                                                </option>

                                            <?php endfor; ?>

                                        </select>

                                    </div>

                                    <div>

                                        <label
                                            for="lastDateMonth"
                                            class="form-label"
                                        >
                                            Month
                                        </label>

                                        <select
                                            name="last_date_month"
                                            id="lastDateMonth"
                                            class="form-select"
                                        >

                                            <?php foreach (
                                                EthiopianCalendar::months()
                                                as $monthNumber => $monthName
                                            ): ?>

                                                <option
                                                    value="<?= $monthNumber ?>"
                                                    <?= $monthNumber === $lastDateMonth ? 'selected' : '' ?>
                                                >
                                                    <?= e($monthName) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>

                                    <div>

                                        <label
                                            for="lastDateDay"
                                            class="form-label"
                                        >
                                            Day
                                        </label>

                                        <select
                                            name="last_date_day"
                                            id="lastDateDay"
                                            class="form-select"
                                        >

                                            <?php
                                            $selectedDays =
                                                EthiopianCalendar::daysInMonth(
                                                    $lastDateYear,
                                                    $lastDateMonth
                                                );

                                            for (
                                                $day = 1;
                                                $day <= $selectedDays;
                                                $day++
                                            ):
                                            ?>

                                                <option
                                                    value="<?= $day ?>"
                                                    <?= $day === $lastDateDay ? 'selected' : '' ?>
                                                >
                                                    <?= $day ?>
                                                </option>

                                            <?php endfor; ?>

                                        </select>

                                    </div>

                                </div>

                                <div
                                    class="ethiopian-date-preview"
                                    id="ethiopianDatePreview"
                                >
                                    <?= e(
                                        EthiopianCalendar::monthName(
                                            $lastDateMonth
                                        )
                                    ) ?>
                                    <?= $lastDateDay ?>,
                                    <?= $lastDateYear ?>
                                </div>

                                <div class="date-help">
                                    After this Ethiopian date, the announcement will no longer be visible to its selected audience.
                                </div>

                            </div>

                        </div>

                    </div>

                    <div class="actions">

                        <a
                            href="../announcements.php"
                            class="btn btn-custom btn-cancel"
                        >
                            Cancel
                        </a>

                        <button
                            type="submit"
                            class="btn btn-custom btn-primary-custom"
                            id="submitButton"
                        >
                            <i class="bi bi-check2-circle me-1"></i>
                            Save Announcement
                        </button>

                    </div>

                </div>

            </div>

        </form>

    </section>

</main>

<script>

    /*
    |--------------------------------------------------------------------------
    | Mobile sidebar
    |--------------------------------------------------------------------------
    */

    const sidebar =
        document.getElementById('sidebar');

    const overlay =
        document.getElementById('overlay');

    const menuToggle =
        document.getElementById('menuToggle');

    function openSidebar() {

        sidebar.classList.add('open');

        overlay.classList.add('show');
    }

    function closeSidebar() {

        sidebar.classList.remove('open');

        overlay.classList.remove('show');
    }

    if (menuToggle) {

        menuToggle.addEventListener(
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

    /*
    |--------------------------------------------------------------------------
    | Student audience
    |--------------------------------------------------------------------------
    */

    const audienceStudent =
        document.getElementById(
            'audienceStudent'
        );

    const studentOptions =
        document.getElementById(
            'studentOptions'
        );

    const studentAllGrades =
        document.getElementById(
            'studentAllGrades'
        );

    const studentGrades =
        document.querySelectorAll(
            '.student-grade'
        );

    function updateStudentAudience() {

        if (audienceStudent.checked) {

            studentOptions.style.display =
                '';

        } else {

            studentOptions.style.display =
                'none';

            studentAllGrades.checked =
                false;

            studentGrades.forEach(
                function (input) {

                    input.checked =
                        false;

                    input.disabled =
                        false;
                }
            );
        }
    }

    function updateStudentGrades() {

        if (studentAllGrades.checked) {

            studentGrades.forEach(
                function (input) {

                    input.checked =
                        false;

                    input.disabled =
                        true;
                }
            );

        } else {

            studentGrades.forEach(
                function (input) {

                    input.disabled =
                        false;
                }
            );
        }
    }

    audienceStudent.addEventListener(
        'change',
        updateStudentAudience
    );

    studentAllGrades.addEventListener(
        'change',
        updateStudentGrades
    );

    /*
    |--------------------------------------------------------------------------
    | Parent audience
    |--------------------------------------------------------------------------
    */

    const audienceParent =
        document.getElementById(
            'audienceParent'
        );

    const parentOptions =
        document.getElementById(
            'parentOptions'
        );

    const parentAllGrades =
        document.getElementById(
            'parentAllGrades'
        );

    const parentGrades =
        document.querySelectorAll(
            '.parent-grade'
        );

    function updateParentAudience() {

        if (audienceParent.checked) {

            parentOptions.style.display =
                '';

        } else {

            parentOptions.style.display =
                'none';

            parentAllGrades.checked =
                false;

            parentGrades.forEach(
                function (input) {

                    input.checked =
                        false;

                    input.disabled =
                        false;
                }
            );
        }
    }

    function updateParentGrades() {

        if (parentAllGrades.checked) {

            parentGrades.forEach(
                function (input) {

                    input.checked =
                        false;

                    input.disabled =
                        true;
                }
            );

        } else {

            parentGrades.forEach(
                function (input) {

                    input.disabled =
                        false;
                }
            );
        }
    }

    audienceParent.addEventListener(
        'change',
        updateParentAudience
    );

    parentAllGrades.addEventListener(
        'change',
        updateParentGrades
    );

    /*
    |--------------------------------------------------------------------------
    | Ethiopian date
    |--------------------------------------------------------------------------
    */

    const lastDateEnabled =
        document.getElementById(
            'lastDateEnabled'
        );

    const lastDateFields =
        document.getElementById(
            'lastDateFields'
        );

    const lastDateYear =
        document.getElementById(
            'lastDateYear'
        );

    const lastDateMonth =
        document.getElementById(
            'lastDateMonth'
        );

    const lastDateDay =
        document.getElementById(
            'lastDateDay'
        );

    const ethiopianDatePreview =
        document.getElementById(
            'ethiopianDatePreview'
        );

    const lastDateRequired =
        document.getElementById(
            'lastDateRequired'
        );

    /*
    |--------------------------------------------------------------------------
    | Ethiopian month names
    |--------------------------------------------------------------------------
    */

    const ethiopianMonths = {
        1: 'Meskerem',
        2: 'Tikimt',
        3: 'Hidar',
        4: 'Tahsas',
        5: 'Tir',
        6: 'Yekatit',
        7: 'Megabit',
        8: 'Miyazya',
        9: 'Ginbot',
        10: 'Sene',
        11: 'Hamle',
        12: 'Nehase',
        13: 'Pagume'
    };

    function updateLastDateRequired() {

        const published =
            document.getElementById(
                'statusPublished'
            ).checked;

        if (published) {

            lastDateRequired.style.display =
                '';

        } else {

            lastDateRequired.style.display =
                'none';
        }
    }

    function updateLastDateFields() {

        if (lastDateEnabled.checked) {

            lastDateFields.style.display =
                '';

        } else {

            lastDateFields.style.display =
                'none';
        }
    }

    function updateDayOptions() {

        const year =
            parseInt(
                lastDateYear.value,
                10
            );

        const month =
            parseInt(
                lastDateMonth.value,
                10
            );

        let days = 30;

        if (month === 13) {

            days =
                year % 4 === 3
                    ? 6
                    : 5;
        }

        const currentDay =
            parseInt(
                lastDateDay.value,
                10
            );

        lastDateDay.innerHTML =
            '';

        for (
            let day = 1;
            day <= days;
            day++
        ) {

            const option =
                document.createElement(
                    'option'
                );

            option.value =
                String(day);

            option.textContent =
                String(day);

            if (
                day === currentDay &&
                currentDay <= days
            ) {
                option.selected =
                    true;
            }

            lastDateDay.appendChild(
                option
            );
        }

        if (
            currentDay > days
        ) {

            lastDateDay.value =
                String(days);
        }

        updateDatePreview();
    }

    function updateDatePreview() {

        const year =
            lastDateYear.value;

        const month =
            parseInt(
                lastDateMonth.value,
                10
            );

        const day =
            lastDateDay.value;

        ethiopianDatePreview.textContent =
            ethiopianMonths[month] +
            ' ' +
            day +
            ', ' +
            year;
    }

    lastDateEnabled.addEventListener(
        'change',
        updateLastDateFields
    );

    lastDateYear.addEventListener(
        'change',
        updateDayOptions
    );

    lastDateMonth.addEventListener(
        'change',
        updateDayOptions
    );

    lastDateDay.addEventListener(
        'change',
        updateDatePreview
    );

    document
        .getElementById('statusDraft')
        .addEventListener(
            'change',
            updateLastDateRequired
        );

    document
        .getElementById('statusPublished')
        .addEventListener(
            'change',
            updateLastDateRequired
        );

    /*
    |--------------------------------------------------------------------------
    | Initial state
    |--------------------------------------------------------------------------
    */

    updateStudentAudience();

    updateStudentGrades();

    updateParentAudience();

    updateParentGrades();

    updateLastDateFields();

    updateDayOptions();

    updateLastDateRequired();

    /*
    |--------------------------------------------------------------------------
    | Prevent double submit
    |--------------------------------------------------------------------------
    */

    const announcementForm =
        document.getElementById(
            'announcementForm'
        );

    const submitButton =
        document.getElementById(
            'submitButton'
        );

    announcementForm.addEventListener(
        'submit',
        function () {

            submitButton.disabled =
                true;

            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-2"></span>' +
                'Saving...';
        }
    );

</script>

</body>

</html>