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
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';

/*
|--------------------------------------------------------------------------
| Ethiopian Calendar
|--------------------------------------------------------------------------
*/

final class EthiopianCalendar
{
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

    private const DAYS_OF_WEEK = [
        0 => [
            'en' => 'Sunday',
            'am' => 'እሑድ',
        ],
        1 => [
            'en' => 'Monday',
            'am' => 'ሰኞ',
        ],
        2 => [
            'en' => 'Tuesday',
            'am' => 'ማክሰኞ',
        ],
        3 => [
            'en' => 'Wednesday',
            'am' => 'ረቡዕ',
        ],
        4 => [
            'en' => 'Thursday',
            'am' => 'ሐሙስ',
        ],
        5 => [
            'en' => 'Friday',
            'am' => 'ዓርብ',
        ],
        6 => [
            'en' => 'Saturday',
            'am' => 'ቅዳሜ',
        ],
    ];

    private const TIMEZONE = 'Africa/Addis_Ababa';

    public static function gregorianToEthiopian(
        int $year,
        int $month,
        int $day
    ): array {
        self::validateGregorianDate($year, $month, $day);

        $gregorianDate = self::createDate(
            $year,
            $month,
            $day
        );

        $ethiopianYear = $year - 7;

        $newYearDate =
            self::ethiopianNewYearDate($ethiopianYear);

        if ($gregorianDate < $newYearDate) {
            $ethiopianYear--;

            $newYearDate =
                self::ethiopianNewYearDate($ethiopianYear);
        }

        $daysSinceNewYear =
            self::daysBetween(
                $newYearDate,
                $gregorianDate
            );

        $ethiopianMonth =
            intdiv(
                $daysSinceNewYear,
                30
            ) + 1;

        $ethiopianDay =
            ($daysSinceNewYear % 30) + 1;

        $phpDayOfWeek =
            (int) $gregorianDate->format('N');

        $dayOfWeek =
            $phpDayOfWeek % 7;

        return [
            'year' => $ethiopianYear,
            'month' => $ethiopianMonth,
            'month_name' =>
                self::monthName(
                    $ethiopianMonth,
                    'en'
                ),
            'month_name_am' =>
                self::monthName(
                    $ethiopianMonth,
                    'am'
                ),
            'day' => $ethiopianDay,
            'day_of_week' => $dayOfWeek,
            'day_name' =>
                self::dayOfWeekName(
                    $dayOfWeek,
                    'en'
                ),
            'day_name_am' =>
                self::dayOfWeekName(
                    $dayOfWeek,
                    'am'
                ),
            'is_leap_year' =>
                self::isLeapYear(
                    $ethiopianYear
                ),
            'formatted' =>
                self::format(
                    $ethiopianYear,
                    $ethiopianMonth,
                    $ethiopianDay,
                    'en'
                ),
            'formatted_am' =>
                self::format(
                    $ethiopianYear,
                    $ethiopianMonth,
                    $ethiopianDay,
                    'am'
                ),
        ];
    }

    public static function ethiopianToGregorian(
        int $year,
        int $month,
        int $day
    ): array {
        self::validateEthiopianDate(
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
            'year' =>
                (int) $gregorianDate->format('Y'),
            'month' =>
                (int) $gregorianDate->format('m'),
            'day' =>
                (int) $gregorianDate->format('d'),
        ];
    }

    public static function fromGregorian(
        string $date
    ): array {
        $dateObject =
            DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $date,
                new DateTimeZone(
                    self::TIMEZONE
                )
            );

        $errors =
            DateTimeImmutable::getLastErrors();

        if (
            !$dateObject ||
            (
                is_array($errors) &&
                (
                    $errors['warning_count'] > 0 ||
                    $errors['error_count'] > 0
                )
            ) ||
            $dateObject->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException(
                'Invalid Gregorian date format. Expected Y-m-d.'
            );
        }

        return self::gregorianToEthiopian(
            (int) $dateObject->format('Y'),
            (int) $dateObject->format('m'),
            (int) $dateObject->format('d')
        );
    }

    public static function toGregorian(
        int $year,
        int $month,
        int $day
    ): string {
        $gregorian =
            self::ethiopianToGregorian(
                $year,
                $month,
                $day
            );

        return sprintf(
            '%04d-%02d-%02d',
            $gregorian['year'],
            $gregorian['month'],
            $gregorian['day']
        );
    }

    public static function today(): array
    {
        $today =
            new DateTimeImmutable(
                'now',
                new DateTimeZone(
                    self::TIMEZONE
                )
            );

        return self::gregorianToEthiopian(
            (int) $today->format('Y'),
            (int) $today->format('m'),
            (int) $today->format('d')
        );
    }

    public static function todayFormatted(
        string $lang = 'en'
    ): string {
        $date = self::today();

        return $lang === 'am'
            ? $date['formatted_am']
            : $date['formatted'];
    }

    public static function addDays(
        int $year,
        int $month,
        int $day,
        int $days
    ): array {
        self::validateEthiopianDate(
            $year,
            $month,
            $day
        );

        $gregorian =
            self::ethiopianToGregorian(
                $year,
                $month,
                $day
            );

        $date =
            self::createDate(
                $gregorian['year'],
                $gregorian['month'],
                $gregorian['day']
            );

        $newDate =
            $date->modify(
                ($days >= 0 ? '+' : '') .
                $days .
                ' days'
            );

        return self::gregorianToEthiopian(
            (int) $newDate->format('Y'),
            (int) $newDate->format('m'),
            (int) $newDate->format('d')
        );
    }

    public static function subDays(
        int $year,
        int $month,
        int $day,
        int $days
    ): array {
        return self::addDays(
            $year,
            $month,
            $day,
            -$days
        );
    }

    public static function diffInDays(
        int $year1,
        int $month1,
        int $day1,
        int $year2,
        int $month2,
        int $day2
    ): int {
        self::validateEthiopianDate(
            $year1,
            $month1,
            $day1
        );

        self::validateEthiopianDate(
            $year2,
            $month2,
            $day2
        );

        $gregorian1 =
            self::ethiopianToGregorian(
                $year1,
                $month1,
                $day1
            );

        $gregorian2 =
            self::ethiopianToGregorian(
                $year2,
                $month2,
                $day2
            );

        $date1 =
            self::createDate(
                $gregorian1['year'],
                $gregorian1['month'],
                $gregorian1['day']
            );

        $date2 =
            self::createDate(
                $gregorian2['year'],
                $gregorian2['month'],
                $gregorian2['day']
            );

        return (int) $date1
            ->diff($date2)
            ->format('%r%a');
    }

    public static function format(
        int $year,
        int $month,
        int $day,
        string $lang = 'en'
    ): string {
        return sprintf(
            '%s %d, %d',
            self::monthName(
                $month,
                $lang
            ),
            $day,
            $year
        );
    }

    public static function monthName(
        int $month,
        string $lang = 'en'
    ): string {
        $months =
            $lang === 'am'
                ? self::MONTHS_AM
                : self::MONTHS_EN;

        return $months[$month] ?? '';
    }

    public static function dayOfWeekName(
        int $dayOfWeek,
        string $lang = 'en'
    ): string {
        return self::DAYS_OF_WEEK[
            $dayOfWeek
        ][$lang] ?? '';
    }

    public static function DAY_OF_WEEK_NAME(
        int $dayOfWeek,
        string $lang = 'en'
    ): string {
        return self::dayOfWeekName(
            $dayOfWeek,
            $lang
        );
    }

    public static function months(
        string $lang = 'en'
    ): array {
        return $lang === 'am'
            ? self::MONTHS_AM
            : self::MONTHS_EN;
    }

    public static function isLeapYear(
        int $year
    ): bool {
        return ($year % 4) === 3;
    }

    public static function daysInMonth(
        int $year,
        int $month
    ): int {
        if (
            $month < 1 ||
            $month > 13
        ) {
            throw new InvalidArgumentException(
                'Ethiopian month must be between 1 and 13.'
            );
        }

        if ($month === 13) {
            return self::isLeapYear($year)
                ? 6
                : 5;
        }

        return 30;
    }

    private static function ethiopianNewYearDate(
        int $ethiopianYear
    ): DateTimeImmutable {
        if ($ethiopianYear < 1) {
            throw new InvalidArgumentException(
                'Ethiopian year must be greater than zero.'
            );
        }

        $gregorianYear =
            $ethiopianYear + 7;

        $followingGregorianYear =
            $gregorianYear + 1;

        $newYearDay =
            self::isGregorianLeapYear(
                $followingGregorianYear
            )
                ? 12
                : 11;

        return self::createDate(
            $gregorianYear,
            9,
            $newYearDay
        );
    }

    private static function isGregorianLeapYear(
        int $year
    ): bool {
        return (
            ($year % 400 === 0) ||
            (
                $year % 4 === 0 &&
                $year % 100 !== 0
            )
        );
    }

    private static function createDate(
        int $year,
        int $month,
        int $day
    ): DateTimeImmutable {
        return new DateTimeImmutable(
            sprintf(
                '%04d-%02d-%02d',
                $year,
                $month,
                $day
            ),
            new DateTimeZone(
                self::TIMEZONE
            )
        );
    }

    private static function daysBetween(
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): int {
        return (int) $from
            ->diff($to)
            ->format('%r%a');
    }

    private static function validateGregorianDate(
        int $year,
        int $month,
        int $day
    ): void {
        if (!checkdate(
            $month,
            $day,
            $year
        )) {
            throw new InvalidArgumentException(
                'Invalid Gregorian date.'
            );
        }
    }

    private static function validateEthiopianDate(
        int $year,
        int $month,
        int $day
    ): void {
        if ($year < 1) {
            throw new InvalidArgumentException(
                'Ethiopian year must be greater than zero.'
            );
        }

        if (
            $month < 1 ||
            $month > 13
        ) {
            throw new InvalidArgumentException(
                'Ethiopian month must be between 1 and 13.'
            );
        }

        $maximumDay =
            self::daysInMonth(
                $year,
                $month
            );

        if (
            $day < 1 ||
            $day > $maximumDay
        ) {
            throw new InvalidArgumentException(
                'Invalid Ethiopian day for given month/year.'
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

if (
    !isset($conn) ||
    !($conn instanceof mysqli)
) {
    die(
        'Database connection is not available.'
    );
}

$db = $conn;

/*
|--------------------------------------------------------------------------
| Helpers
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

function formatEthiopianDate(
    ?string $date
): string {
    if (
        empty($date)
    ) {
        return '—';
    }

    try {
        return EthiopianCalendar::fromGregorian(
            substr(
                $date,
                0,
                10
            )
        )['formatted'];
    } catch (Throwable) {
        return '—';
    }
}

function getQueryString(
    string $key,
    string $default = ''
): string {
    return isset($_GET[$key])
        ? trim((string) $_GET[$key])
        : $default;
}

/**
 * Safely bind a dynamic array of parameters.
 */
function bindDynamicParams(
    mysqli_stmt $stmt,
    string $types,
    array &$params
): void {
    if ($types === '') {
        return;
    }

    $bindValues = [];

    $bindValues[] = $types;

    foreach ($params as $key => &$value) {
        $bindValues[] =& $value;
    }

    call_user_func_array(
        [$stmt, 'bind_param'],
        $bindValues
    );

    unset($value);
}

function getPageUrl(
    int $page,
    string $search,
    string $status
): string {
    $params = [
        'page' => $page,
    ];

    if ($search !== '') {
        $params['search'] = $search;
    }

    if ($status !== 'all') {
        $params['status'] = $status;
    }

    return 'announcements.php?' .
        http_build_query($params);
}

function statusClass(
    string $status
): string {
    return match (
        strtolower($status)
    ) {
        'published' =>
            'status-published',

        'draft' =>
            'status-draft',

        'closed' =>
            'status-closed',

        default =>
            'status-draft',
    };
}

function statusIcon(
    string $status
): string {
    return match (
        strtolower($status)
    ) {
        'published' =>
            'bi-check-circle-fill',

        'draft' =>
            'bi-pencil-square',

        'closed' =>
            'bi-lock-fill',

        default =>
            'bi-circle',
    };
}

function getAudienceSummary(
    mysqli $db,
    int $announcementId
): string {
    $summary = [];

    $stmt = $db->prepare("
        SELECT
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
            grade ASC
    ");

    if (!$stmt) {
        return '—';
    }

    $stmt->bind_param(
        'i',
        $announcementId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $studentsAll = false;
    $parentsAll = false;
    $teachersAll = false;
    $public = false;

    $studentGrades = [];
    $parentGrades = [];

    while (
        $row =
        $result->fetch_assoc()
    ) {
        $type =
            (string) $row['audience_type'];

        $grade =
            $row['grade'];

        if ($type === 'Public') {
            $public = true;
        }

        if ($type === 'Teacher') {
            $teachersAll = true;
        }

        if ($type === 'Student') {
            if ($grade === null) {
                $studentsAll = true;
            } else {
                $studentGrades[] =
                    (int) $grade;
            }
        }

        if ($type === 'Parent') {
            if ($grade === null) {
                $parentsAll = true;
            } else {
                $parentGrades[] =
                    (int) $grade;
            }
        }
    }

    $stmt->close();

    if ($public) {
        $summary[] = 'Public';
    }

    if ($studentsAll) {
        $summary[] = 'All Students';
    } elseif (!empty($studentGrades)) {
        $grades =
            array_unique(
                $studentGrades
            );

        sort($grades);

        $summary[] =
            'Students: ' .
            implode(
                ', ',
                array_map(
                    static fn(
                        int $grade
                    ): string =>
                        'Grade ' . $grade,
                    $grades
                )
            );
    }

    if ($parentsAll) {
        $summary[] = 'All Parents';
    } elseif (!empty($parentGrades)) {
        $grades =
            array_unique(
                $parentGrades
            );

        sort($grades);

        $summary[] =
            'Parents: ' .
            implode(
                ', ',
                array_map(
                    static fn(
                        int $grade
                    ): string =>
                        'Grade ' . $grade,
                    $grades
                )
            );
    }

    if ($teachersAll) {
        $summary[] = 'All Teachers';
    }

    return !empty($summary)
        ? implode(
            ' • ',
            $summary
        )
        : 'No audience';
}

/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token']) ||
    $_SESSION['csrf_token'] === ''
) {
    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrfToken =
    $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| Search and status
|--------------------------------------------------------------------------
|
| These must be initialized BEFORE the delete handler because the
| delete redirect uses them.
|
*/

$search =
    getQueryString('search');

$status =
    strtolower(
        getQueryString(
            'status',
            'all'
        )
    );

$allowedStatuses = [
    'all',
    'draft',
    'published',
    'closed',
];

if (
    !in_array(
        $status,
        $allowedStatuses,
        true
    )
) {
    $status = 'all';
}

/*
|--------------------------------------------------------------------------
| Automatically close expired published announcements
|--------------------------------------------------------------------------
|
| closed_at contains the selected last date at 23:59:59.
| Once that date has passed, the announcement becomes Closed.
|
*/

$expireStmt = $db->prepare("
    UPDATE announcements
    SET
        status = 'Closed',
        updated_at = CURRENT_TIMESTAMP
    WHERE status = 'Published'
      AND closed_at IS NOT NULL
      AND closed_at < NOW()
");

if ($expireStmt) {
    $expireStmt->execute();
    $expireStmt->close();
}

/*
|--------------------------------------------------------------------------
| Delete announcement
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_announcement'])
) {
    $postedToken =
        (string) (
            $_POST['csrf_token'] ?? ''
        );

    $announcementId =
        filter_input(
            INPUT_POST,
            'announcement_id',
            FILTER_VALIDATE_INT
        );

    if (
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {
        $_SESSION['announcement_error'] =
            'Invalid security token.';
    } elseif (
        !$announcementId ||
        $announcementId < 1
    ) {
        $_SESSION['announcement_error'] =
            'Invalid announcement.';
    } else {
        $stmt = $db->prepare("
            DELETE FROM announcements
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param(
                'i',
                $announcementId
            );

            if ($stmt->execute()) {
                if (
                    $stmt->affected_rows > 0
                ) {
                    $_SESSION['announcement_success'] =
                        'Announcement deleted successfully.';
                } else {
                    $_SESSION['announcement_error'] =
                        'Announcement could not be found.';
                }
            } else {
                $_SESSION['announcement_error'] =
                    'Announcement could not be deleted.';
            }

            $stmt->close();
        } else {
            $_SESSION['announcement_error'] =
                'Unable to prepare delete request.';
        }
    }

    $redirectUrl =
        'announcements.php';

    $redirectParams = [];

    if ($search !== '') {
        $redirectParams['search'] =
            $search;
    }

    if ($status !== 'all') {
        $redirectParams['status'] =
            $status;
    }

    if (!empty($redirectParams)) {
        $redirectUrl .= '?' .
            http_build_query(
                $redirectParams
            );
    }

    header(
        'Location: ' .
        $redirectUrl
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Flash messages
|--------------------------------------------------------------------------
*/

$successMessage =
    $_SESSION['announcement_success']
    ?? '';

$errorMessage =
    $_SESSION['announcement_error']
    ?? '';

unset(
    $_SESSION['announcement_success'],
    $_SESSION['announcement_error']
);

/*
|--------------------------------------------------------------------------
| Principal information
|--------------------------------------------------------------------------
*/

$principalId =
    (int) $_SESSION['user_id'];

$principal = [
    'full_name' => 'Principal',
    'photo' => '',
];

$stmt = $db->prepare("
    SELECT
        u.id,
        u.full_name,
        u.email,
        u.phone,
        p.photo
    FROM users AS u
    LEFT JOIN principals AS p
        ON p.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'principal'
      AND u.is_deleted = 0
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        'i',
        $principalId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    if (
        $row =
        $result->fetch_assoc()
    ) {
        $principal = $row;
    }

    $stmt->close();
}

$principalPhoto =
    trim(
        (string) (
            $principal['photo']
            ?? ''
        )
    );

$principalPhotoUrl = '';

if ($principalPhoto !== '') {
    $principalPhotoUrl =
        '../' .
        ltrim(
            $principalPhoto,
            '/'
        );
}

$principalName =
    trim(
        (string) (
            $principal['full_name']
            ?? 'Principal'
        )
    );

$principalInitial =
    strtoupper(
        substr(
            $principalName,
            0,
            1
        )
    );

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$perPage = 10;

$page =
    max(
        1,
        (int) getQueryString(
            'page',
            '1'
        )
    );

$offset =
    ($page - 1) *
    $perPage;

/*
|--------------------------------------------------------------------------
| WHERE conditions
|--------------------------------------------------------------------------
*/

$where = [];

$params = [];

$types = '';

if ($search !== '') {
    $where[] = "
        (
            a.title LIKE ?
            OR a.content LIKE ?
        )
    ";

    $searchValue =
        '%' .
        $search .
        '%';

    $params[] =
        $searchValue;

    $params[] =
        $searchValue;

    $types .= 'ss';
}

if ($status !== 'all') {
    $where[] =
        'LOWER(a.status) = ?';

    $params[] =
        $status;

    $types .= 's';
}

$whereSql = '';

if (!empty($where)) {
    $whereSql =
        'WHERE ' .
        implode(
            ' AND ',
            $where
        );
}

/*
|--------------------------------------------------------------------------
| Count announcements
|--------------------------------------------------------------------------
*/

$totalAnnouncements = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM announcements AS a
    $whereSql
";

$stmt =
    $db->prepare(
        $countSql
    );

if ($stmt) {
    if ($types !== '') {
        bindDynamicParams(
            $stmt,
            $types,
            $params
        );
    }

    $stmt->execute();

    $result =
        $stmt->get_result();

    if (
        $row =
        $result->fetch_assoc()
    ) {
        $totalAnnouncements =
            (int) $row['total'];
    }

    $stmt->close();
}

$totalPages =
    max(
        1,
        (int) ceil(
            $totalAnnouncements /
            $perPage
        )
    );

if ($page > $totalPages) {
    $page =
        $totalPages;

    $offset =
        ($page - 1) *
        $perPage;
}

/*
|--------------------------------------------------------------------------
| Load announcements
|--------------------------------------------------------------------------
*/

$announcements = [];

$sql = "
    SELECT
        a.id,
        a.title,
        a.content,
        a.status,
        a.created_by,
        a.published_at,
        a.closed_at,
        a.created_at,
        a.updated_at,

        u.full_name AS creator_name,

        (
            SELECT COUNT(*)
            FROM announcement_audiences aa
            WHERE aa.announcement_id = a.id
        ) AS audience_count,

        (
            SELECT COUNT(*)
            FROM announcement_media am
            WHERE am.announcement_id = a.id
              AND am.media_type = 'Image'
        ) AS image_count,

        (
            SELECT COUNT(*)
            FROM announcement_media am
            WHERE am.announcement_id = a.id
              AND am.media_type = 'Attachment'
        ) AS attachment_count

    FROM announcements AS a

    LEFT JOIN users AS u
        ON u.id = a.created_by

    $whereSql

    ORDER BY
        CASE
            WHEN a.status = 'Published' THEN 1
            WHEN a.status = 'Draft' THEN 2
            WHEN a.status = 'Closed' THEN 3
            ELSE 4
        END,
        a.created_at DESC

    LIMIT ? OFFSET ?
";

$stmt =
    $db->prepare($sql);

if ($stmt) {
    $queryTypes =
        $types . 'ii';

    $queryParams =
        $params;

    $queryParams[] =
        $perPage;

    $queryParams[] =
        $offset;

    bindDynamicParams(
        $stmt,
        $queryTypes,
        $queryParams
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    while (
        $row =
        $result->fetch_assoc()
    ) {
        $announcements[] =
            $row;
    }

    $stmt->close();
}

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
        Announcements | BKHS Principal
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
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
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
            color: #fff;
            z-index: 1050;
            display: flex;
            flex-direction: column;
            transition: transform .3s ease;
        }

        .sidebar-header {
            height: 76px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #fff;
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .brand-text {
            font-size: 16px;
            font-weight: 700;
        }

        .brand-subtitle {
            display: block;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 400;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 18px 12px;
            overflow-y: auto;
            flex: 1;
        }

        .nav-section-title {
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .08em;
            font-weight: 700;
            padding: 10px 12px 8px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: #d1d5db;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            font-size: 17px;
            width: 20px;
            text-align: center;
        }

        .sidebar-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
            transform: translateX(2px);
        }

        .sidebar-link.active {
            color: #fff;
            background: var(--primary);
            box-shadow:
                0 4px 12px
                rgba(79,70,229,.25);
        }

        .sidebar-footer {
            padding: 14px 18px;
            border-top: 1px solid rgba(255,255,255,.08);
            color: #9ca3af;
            font-size: 11px;
        }

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 76px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 900;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
        }

        .topbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 23px;
            color: var(--text);
        }

        .page-title-small {
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .today {
            color: var(--muted);
            font-size: 11px;
            text-align: right;
        }

        .today strong {
            color: var(--text);
            display: block;
            font-size: 12px;
            font-weight: 600;
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
            font-weight: 700;
            border: 2px solid #e0e7ff;
        }

        .principal-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .content {
            padding: 30px;
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 25px;
            font-weight: 700;
            letter-spacing: -.02em;
        }

        .page-heading p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .btn-primary-custom {
            background: var(--primary);
            border: none;
            color: #fff;
            padding: 10px 16px;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all .2s ease;
        }

        .btn-primary-custom:hover {
            background: var(--primary-dark);
            color: #fff;
            transform: translateY(-1px);
            box-shadow:
                0 5px 14px
                rgba(79,70,229,.22);
        }

        .filter-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 18px;
        }

        .filter-form {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .search-wrapper {
            position: relative;
            flex: 1;
        }

        .search-wrapper i {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
        }

        .search-input {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 0 14px 0 39px;
            font-size: 13px;
            outline: none;
            transition:
                border-color .2s,
                box-shadow .2s;
        }

        .search-input:focus {
            border-color: var(--primary);
            box-shadow:
                0 0 0 3px
                rgba(79,70,229,.08);
        }

        .status-select {
            width: 170px;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 9px;
            padding: 0 12px;
            font-size: 13px;
            color: var(--text);
            background: #fff;
            outline: none;
        }

        .filter-btn {
            height: 42px;
            padding: 0 16px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            border-radius: 9px;
            font-size: 13px;
            font-weight: 600;
            transition: all .2s ease;
        }

        .filter-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .clear-btn {
            height: 42px;
            padding: 0 13px;
            color: var(--muted);
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .alert-custom {
            border-radius: 12px;
            border: 1px solid;
            font-size: 13px;
            margin-bottom: 18px;
        }

        .table-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
        }

        .table-header {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-header h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 700;
        }

        .result-count {
            color: var(--muted);
            font-size: 12px;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .announcement-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1080px;
        }

        .announcement-table th {
            background: #f9fafb;
            color: #6b7280;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .06em;
            font-weight: 700;
            padding: 12px 18px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .announcement-table td {
            padding: 15px 18px;
            border-bottom: 1px solid #f0f1f3;
            vertical-align: middle;
            font-size: 12px;
        }

        .announcement-table tbody tr {
            transition: background .2s ease;
        }

        .announcement-table tbody tr:hover {
            background: #fafbff;
        }

        .announcement-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .announcement-title {
            max-width: 270px;
        }

        .announcement-title a {
            color: var(--text);
            font-weight: 600;
            font-size: 13px;
        }

        .announcement-title a:hover {
            color: var(--primary);
        }

        .announcement-description {
            color: var(--muted);
            margin-top: 4px;
            line-height: 1.45;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .audience {
            max-width: 260px;
            color: var(--muted);
            line-height: 1.5;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-published {
            color: #047857;
            background: #ecfdf5;
        }

        .status-draft {
            color: #92400e;
            background: #fffbeb;
        }

        .status-closed {
            color: #4b5563;
            background: #f3f4f6;
        }

        .date-cell {
            white-space: nowrap;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.5;
        }

        .date-label {
            display: block;
            color: #9ca3af;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: 2px;
        }

        .date-value {
            color: var(--muted);
            font-size: 11px;
        }

        .creator {
            white-space: nowrap;
            color: #4b5563;
            font-size: 11px;
        }

        .media-counts {
            display: flex;
            gap: 9px;
            color: var(--muted);
            font-size: 11px;
        }

        .media-counts span {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            border: 1px solid var(--border);
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff;
            color: var(--muted);
            transition: all .2s ease;
        }

        .action-btn:hover {
            color: var(--primary);
            border-color: #c7d2fe;
            background: #eef2ff;
            transform: translateY(-1px);
        }

        .action-btn.delete:hover {
            color: #dc2626;
            border-color: #fecaca;
            background: #fef2f2;
        }

        .empty-state {
            padding: 70px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 15px;
            border-radius: 50%;
            background: #eef2ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
        }

        .empty-state h3 {
            font-size: 16px;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .empty-state p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .pagination-wrapper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 20px;
            border-top: 1px solid var(--border);
        }

        .pagination-info {
            color: var(--muted);
            font-size: 11px;
        }

        .pagination {
            margin: 0;
            gap: 4px;
        }

        .page-link {
            border: 1px solid var(--border);
            color: var(--muted);
            border-radius: 7px !important;
            font-size: 11px;
            min-width: 32px;
            text-align: center;
        }

        .page-item.active .page-link {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .page-link:hover {
            color: var(--primary);
            background: #eef2ff;
            border-color: #c7d2fe;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(17,24,39,.55);
            z-index: 1040;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
            }

            .topbar {
                padding: 0 20px;
            }

            .content {
                padding: 22px 20px;
            }
        }

        @media (max-width: 767.98px) {

            .topbar {
                height: 68px;
            }

            .content {
                padding: 18px 14px;
            }

            .page-heading {
                flex-direction: column;
                margin-bottom: 18px;
            }

            .page-heading h1 {
                font-size: 22px;
            }

            .btn-primary-custom {
                width: 100%;
                justify-content: center;
            }

            .today {
                display: none;
            }

            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }

            .status-select {
                width: 100%;
            }

            .filter-btn {
                width: 100%;
            }

            .clear-btn {
                justify-content: center;
            }

            .table-header {
                padding: 15px;
            }

            .pagination-wrapper {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
        }

    </style>

</head>

<body>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- Sidebar -->
<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-header">

        <a
            href="dashboard.php"
            class="brand"
        >

            <div class="brand-icon">
                <i class="bi bi-building"></i>
            </div>

            <div>

                <div class="brand-text">
                    BKHS
                </div>

                <span class="brand-subtitle">
                    Principal Portal
                </span>

            </div>

        </a>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="announcements.php"
            class="sidebar-link active"
        >
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

        <a
            href="subject-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-book-half"></i>
            <span>Subject Assignment</span>
        </a>

        <a
            href="homeroom-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-workspace"></i>
            <span>Homeroom Assignment</span>
        </a>

        <a
            href="student-assignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-check-fill"></i>
            <span>Student Assignment</span>
        </a>

        <a
            href="attendance.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Attendance</span>
        </a>

        <a
            href="roster.php"
            class="sidebar-link"
        >
            <i class="bi bi-people-fill"></i>
            <span>Roster</span>
        </a>

        <a
            href="certificate.php"
            class="sidebar-link"
        >
            <i class="bi bi-award-fill"></i>
            <span>Certificate</span>
        </a>

        <a
            href="result.php"
            class="sidebar-link"
        >
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <div class="nav-section-title mt-3">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="sidebar-footer">
        BKHS School Management System
    </div>

</aside>

<!-- Main -->
<main class="main">

    <!-- Topbar -->
    <header class="topbar">

        <div class="topbar-left">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open navigation"
            >
                <i class="bi bi-list"></i>
            </button>

            <div class="page-title-small">
                Announcement Management
            </div>

        </div>

        <div class="topbar-right">

            <div class="today">

                <strong>
                    <?= e(
                        EthiopianCalendar::todayFormatted(
                            'en'
                        )
                    ) ?>
                </strong>

                <span>
                    Ethiopian Calendar
                </span>

            </div>

            <div class="principal-avatar">

                <?php if (
                    $principalPhotoUrl !== ''
                ): ?>

                    <img
                        src="<?= e(
                            $principalPhotoUrl
                        ) ?>"
                        alt="Principal"
                    >

                <?php else: ?>

                    <?= e(
                        $principalInitial
                    ) ?>

                <?php endif; ?>

            </div>

        </div>

    </header>

    <!-- Content -->
    <div class="content">

        <div class="page-heading">

            <div>

                <h1>
                    Announcements
                </h1>

                <p>
                    Create, publish, and manage school announcements.
                </p>

            </div>

            <a
                href="announcements/create.php"
                class="btn-primary-custom"
            >
                <i class="bi bi-plus-lg"></i>
                Create Announcement
            </a>

        </div>

        <?php if (
            $successMessage !== ''
        ): ?>

            <div
                class="alert alert-success alert-custom"
                role="alert"
            >
                <i class="bi bi-check-circle-fill me-2"></i>
                <?= e(
                    $successMessage
                ) ?>
            </div>

        <?php endif; ?>

        <?php if (
            $errorMessage !== ''
        ): ?>

            <div
                class="alert alert-danger alert-custom"
                role="alert"
            >
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= e(
                    $errorMessage
                ) ?>
            </div>

        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-card">

            <form
                method="GET"
                action="announcements.php"
                class="filter-form"
            >

                <div class="search-wrapper">

                    <i class="bi bi-search"></i>

                    <input
                        type="search"
                        name="search"
                        class="search-input"
                        placeholder="Search announcements..."
                        value="<?= e(
                            $search
                        ) ?>"
                    >

                </div>

                <select
                    name="status"
                    class="status-select"
                >

                    <option
                        value="all"
                        <?= $status === 'all'
                            ? 'selected'
                            : '' ?>
                    >
                        All Statuses
                    </option>

                    <option
                        value="published"
                        <?= $status === 'published'
                            ? 'selected'
                            : '' ?>
                    >
                        Published
                    </option>

                    <option
                        value="draft"
                        <?= $status === 'draft'
                            ? 'selected'
                            : '' ?>
                    >
                        Draft
                    </option>

                    <option
                        value="closed"
                        <?= $status === 'closed'
                            ? 'selected'
                            : '' ?>
                    >
                        Closed
                    </option>

                </select>

                <button
                    type="submit"
                    class="filter-btn"
                >
                    <i class="bi bi-funnel me-1"></i>
                    Filter
                </button>

                <?php if (
                    $search !== '' ||
                    $status !== 'all'
                ): ?>

                    <a
                        href="announcements.php"
                        class="clear-btn"
                    >
                        <i class="bi bi-x-circle"></i>
                        Clear
                    </a>

                <?php endif; ?>

            </form>

        </div>

        <!-- Announcement table -->
        <div class="table-card">

            <div class="table-header">

                <h2>
                    Announcement List
                </h2>

                <span class="result-count">

                    <?= number_format(
                        $totalAnnouncements
                    ) ?>

                    announcement<?= $totalAnnouncements === 1
                        ? ''
                        : 's' ?>

                </span>

            </div>

            <?php if (
                empty($announcements)
            ): ?>

                <div class="empty-state">

                    <div class="empty-icon">
                        <i class="bi bi-megaphone"></i>
                    </div>

                    <h3>
                        No announcements found
                    </h3>

                    <p>

                        <?php if (
                            $search !== '' ||
                            $status !== 'all'
                        ): ?>

                            Try changing your search or filter.

                        <?php else: ?>

                            Create your first announcement
                            to get started.

                        <?php endif; ?>

                    </p>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="announcement-table">

                        <thead>

                            <tr>

                                <th>
                                    Announcement
                                </th>

                                <th>
                                    Audience
                                </th>

                                <th>
                                    Status
                                </th>

                                <th>
                                    Published
                                </th>

                                <th>
                                    Last Date
                                </th>

                                <th>
                                    Created By
                                </th>

                                <th>
                                    Media
                                </th>

                                <th>
                                    Actions
                                </th>

                            </tr>

                        </thead>

                        <tbody>

                        <?php foreach (
                            $announcements
                            as $announcement
                        ): ?>

                            <?php

                            $announcementId =
                                (int) $announcement['id'];

                            $announcementTitle =
                                (string)
                                $announcement['title'];

                            $description =
                                trim(
                                    strip_tags(
                                        (string) (
                                            $announcement['content']
                                            ?? ''
                                        )
                                    )
                                );

                            $announcementStatus =
                                (string)
                                $announcement['status'];

                            $audienceSummary =
                                getAudienceSummary(
                                    $db,
                                    $announcementId
                                );

                            $publishedDate =
                                formatEthiopianDate(
                                    $announcement[
                                        'published_at'
                                    ]
                                );

                            $lastDate =
                                formatEthiopianDate(
                                    $announcement[
                                        'closed_at'
                                    ]
                                );

                            $imageCount =
                                (int) (
                                    $announcement[
                                        'image_count'
                                    ] ?? 0
                                );

                            $attachmentCount =
                                (int) (
                                    $announcement[
                                        'attachment_count'
                                    ] ?? 0
                                );

                            ?>

                            <tr>

                                <!-- Announcement -->
                                <td>

                                    <div class="announcement-title">

                                        <a
                                            href="announcements/view.php?id=<?= $announcementId ?>"
                                        >
                                            <?= e(
                                                $announcementTitle
                                            ) ?>
                                        </a>

                                        <?php if (
                                            $description !== ''
                                        ): ?>

                                            <div
                                                class="announcement-description"
                                            >
                                                <?= e(
                                                    $description
                                                ) ?>
                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <!-- Audience -->
                                <td>

                                    <div class="audience">

                                        <?= e(
                                            $audienceSummary
                                        ) ?>

                                    </div>

                                </td>

                                <!-- Status -->
                                <td>

                                    <span
                                        class="status-badge <?= e(
                                            statusClass(
                                                $announcementStatus
                                            )
                                        ) ?>"
                                    >

                                        <i
                                            class="bi <?= e(
                                                statusIcon(
                                                    $announcementStatus
                                                )
                                            ) ?>"
                                        ></i>

                                        <?= e(
                                            $announcementStatus
                                        ) ?>

                                    </span>

                                </td>

                                <!-- Published -->
                                <td>

                                    <div class="date-cell">

                                        <?php if (
                                            !empty(
                                                $announcement[
                                                    'published_at'
                                                ]
                                            )
                                        ): ?>

                                            <span
                                                class="date-label"
                                            >
                                                Published
                                            </span>

                                            <span
                                                class="date-value"
                                            >
                                                <?= e(
                                                    $publishedDate
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <!-- Last Date -->
                                <td>

                                    <div class="date-cell">

                                        <?php if (
                                            !empty(
                                                $announcement[
                                                    'closed_at'
                                                ]
                                            )
                                        ): ?>

                                            <span
                                                class="date-label"
                                            >
                                                Last Date
                                            </span>

                                            <span
                                                class="date-value"
                                            >
                                                <?= e(
                                                    $lastDate
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="date-value"
                                            >
                                                No expiration
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <!-- Created By -->
                                <td>

                                    <div class="creator">

                                        <?= e(
                                            (string) (
                                                $announcement[
                                                    'creator_name'
                                                ] ??
                                                'Unknown'
                                            )
                                        ) ?>

                                    </div>

                                </td>

                                <!-- Media -->
                                <td>

                                    <div class="media-counts">

                                        <?php if (
                                            $imageCount > 0
                                        ): ?>

                                            <span
                                                title="<?= $imageCount ?> image(s)"
                                            >
                                                <i class="bi bi-image"></i>
                                                <?= $imageCount ?>
                                            </span>

                                        <?php endif; ?>

                                        <?php if (
                                            $attachmentCount > 0
                                        ): ?>

                                            <span
                                                title="<?= $attachmentCount ?> attachment(s)"
                                            >
                                                <i class="bi bi-paperclip"></i>
                                                <?= $attachmentCount ?>
                                            </span>

                                        <?php endif; ?>

                                        <?php if (
                                            $imageCount === 0 &&
                                            $attachmentCount === 0
                                        ): ?>

                                            <span>
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <!-- Actions -->
                                <td>

                                    <div class="actions">

                                        <a
                                            href="announcements/view.php?id=<?= $announcementId ?>"
                                            class="action-btn"
                                            title="View"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </a>

                                        <a
                                            href="announcements/edit.php?id=<?= $announcementId ?>"
                                            class="action-btn"
                                            title="Edit"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>

                                        <form
                                            method="POST"
                                            action="announcements.php"
                                            class="d-inline"
                                            onsubmit="return confirm('Are you sure you want to delete this announcement?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e(
                                                    $csrfToken
                                                ) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="announcement_id"
                                                value="<?= $announcementId ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="delete_announcement"
                                                class="action-btn delete"
                                                title="Delete"
                                            >
                                                <i class="bi bi-trash3"></i>
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

                <?php if (
                    $totalPages > 1
                ): ?>

                    <div class="pagination-wrapper">

                        <div class="pagination-info">

                            Showing
                            <?= number_format(
                                $offset + 1
                            ) ?>

                            –

                            <?= number_format(
                                min(
                                    $offset + $perPage,
                                    $totalAnnouncements
                                )
                            ) ?>

                            of

                            <?= number_format(
                                $totalAnnouncements
                            ) ?>

                        </div>

                        <nav>

                            <ul class="pagination">

                                <li
                                    class="page-item <?= $page <= 1
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $page > 1
                                            ? e(
                                                getPageUrl(
                                                    $page - 1,
                                                    $search,
                                                    $status
                                                )
                                            )
                                            : '#' ?>"
                                    >
                                        <i
                                            class="bi bi-chevron-left"
                                        ></i>
                                    </a>

                                </li>

                                <?php

                                $startPage =
                                    max(
                                        1,
                                        $page - 2
                                    );

                                $endPage =
                                    min(
                                        $totalPages,
                                        $page + 2
                                    );

                                ?>

                                <?php for (
                                    $i = $startPage;
                                    $i <= $endPage;
                                    $i++
                                ): ?>

                                    <li
                                        class="page-item <?= $i === $page
                                            ? 'active'
                                            : '' ?>"
                                    >

                                        <a
                                            class="page-link"
                                            href="<?= e(
                                                getPageUrl(
                                                    $i,
                                                    $search,
                                                    $status
                                                )
                                            ) ?>"
                                        >
                                            <?= $i ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="page-item <?= $page >= $totalPages
                                        ? 'disabled'
                                        : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= $page < $totalPages
                                            ? e(
                                                getPageUrl(
                                                    $page + 1,
                                                    $search,
                                                    $status
                                                )
                                            )
                                            : '#' ?>"
                                    >
                                        <i
                                            class="bi bi-chevron-right"
                                        ></i>
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>

</main>

<script>

    const mobileMenuBtn =
        document.getElementById(
            'mobileMenuBtn'
        );

    const sidebar =
        document.getElementById(
            'sidebar'
        );

    const sidebarOverlay =
        document.getElementById(
            'sidebarOverlay'
        );

    function openSidebar() {
        sidebar.classList.add('show');
        sidebarOverlay.classList.add('show');
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        sidebarOverlay.classList.remove('show');
    }

    if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener(
            'click',
            openSidebar
        );
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener(
            'click',
            closeSidebar
        );
    }

</script>

</body>

</html>