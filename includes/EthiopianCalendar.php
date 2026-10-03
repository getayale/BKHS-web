<?php

declare(strict_types=1);

final class EthiopianCalendar
{
    /**
     * Ethiopian month names in English.
     */
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

    /**
     * Ethiopian month names in Amharic.
     */
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

    /**
     * Days of the week.
     */
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

    /**
     * Ethiopia timezone.
     */
    private const TIMEZONE = 'Africa/Addis_Ababa';

    /**
     * Convert Gregorian date to Ethiopian date.
     *
     * Example:
     * 2026-09-17 => Meskerem 7, 2019
     */
    public static function gregorianToEthiopian(
        int $year,
        int $month,
        int $day
    ): array {
        self::validateGregorianDate($year, $month, $day);

        $gregorianDate = self::createDate($year, $month, $day);

        /*
         * Determine Ethiopian year.
         *
         * Ethiopian New Year normally falls on September 11.
         * It falls on September 12 when the following Gregorian
         * year is a leap year.
         */
        $ethiopianYear = $year - 7;

        $newYearGregorianYear = $year;

        $newYearDate = self::ethiopianNewYearDate($ethiopianYear);

        /*
         * If the Gregorian date occurs before Ethiopian New Year,
         * it belongs to the previous Ethiopian year.
         */
        if ($gregorianDate < $newYearDate) {
            $ethiopianYear--;
            $newYearDate = self::ethiopianNewYearDate($ethiopianYear);
        }

        $daysSinceNewYear = self::daysBetween(
            $newYearDate,
            $gregorianDate
        );

        $ethiopianMonth = intdiv($daysSinceNewYear, 30) + 1;
        $ethiopianDay = ($daysSinceNewYear % 30) + 1;

        /*
         * PHP's N gives:
         * 1 = Monday
         * 7 = Sunday
         *
         * Convert to:
         * 0 = Sunday
         * 1 = Monday
         * ...
         * 6 = Saturday
         */
        $phpDayOfWeek = (int) $gregorianDate->format('N');
        $dayOfWeek = $phpDayOfWeek % 7;

        return [
            'year' => $ethiopianYear,
            'month' => $ethiopianMonth,
            'month_name' => self::monthName($ethiopianMonth, 'en'),
            'month_name_am' => self::monthName($ethiopianMonth, 'am'),
            'day' => $ethiopianDay,
            'day_of_week' => $dayOfWeek,
            'day_name' => self::dayOfWeekName($dayOfWeek, 'en'),
            'day_name_am' => self::dayOfWeekName($dayOfWeek, 'am'),
            'is_leap_year' => self::isLeapYear($ethiopianYear),
            'formatted' => self::format(
                $ethiopianYear,
                $ethiopianMonth,
                $ethiopianDay,
                'en'
            ),
            'formatted_am' => self::format(
                $ethiopianYear,
                $ethiopianMonth,
                $ethiopianDay,
                'am'
            ),
        ];
    }

    /**
     * Convert Ethiopian date to Gregorian date structure.
     */
    public static function ethiopianToGregorian(
        int $year,
        int $month,
        int $day
    ): array {
        self::validateEthiopianDate($year, $month, $day);

        $newYearDate = self::ethiopianNewYearDate($year);

        $daysToAdd = (($month - 1) * 30) + ($day - 1);

        $gregorianDate = $newYearDate->modify(
            '+' . $daysToAdd . ' days'
        );

        return [
            'year' => (int) $gregorianDate->format('Y'),
            'month' => (int) $gregorianDate->format('m'),
            'day' => (int) $gregorianDate->format('d'),
        ];
    }

    /**
     * Convert Gregorian Y-m-d string to Ethiopian date.
     */
    public static function fromGregorian(string $date): array
    {
        $dateObject = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            new DateTimeZone(self::TIMEZONE)
        );

        $errors = DateTimeImmutable::getLastErrors();

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

    /**
     * Convert Ethiopian date to Gregorian Y-m-d.
     */
    public static function toGregorian(
        int $year,
        int $month,
        int $day
    ): string {
        $gregorian = self::ethiopianToGregorian(
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

    /**
     * Get today's Ethiopian date using Ethiopia timezone.
     */
    public static function today(): array
    {
        $today = new DateTimeImmutable(
            'now',
            new DateTimeZone(self::TIMEZONE)
        );

        return self::gregorianToEthiopian(
            (int) $today->format('Y'),
            (int) $today->format('m'),
            (int) $today->format('d')
        );
    }

    /**
     * Get today's formatted Ethiopian date.
     *
     * Example:
     * Meskerem 7, 2019
     */
    public static function todayFormatted(
        string $lang = 'en'
    ): string {
        $date = self::today();

        return $lang === 'am'
            ? $date['formatted_am']
            : $date['formatted'];
    }

    /**
     * Add days to an Ethiopian date.
     */
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

        $gregorian = self::ethiopianToGregorian(
            $year,
            $month,
            $day
        );

        $date = self::createDate(
            $gregorian['year'],
            $gregorian['month'],
            $gregorian['day']
        );

        $newDate = $date->modify(
            ($days >= 0 ? '+' : '') . $days . ' days'
        );

        return self::gregorianToEthiopian(
            (int) $newDate->format('Y'),
            (int) $newDate->format('m'),
            (int) $newDate->format('d')
        );
    }

    /**
     * Subtract days from an Ethiopian date.
     */
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

    /**
     * Difference in days between two Ethiopian dates.
     */
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

        $gregorian1 = self::ethiopianToGregorian(
            $year1,
            $month1,
            $day1
        );

        $gregorian2 = self::ethiopianToGregorian(
            $year2,
            $month2,
            $day2
        );

        $date1 = self::createDate(
            $gregorian1['year'],
            $gregorian1['month'],
            $gregorian1['day']
        );

        $date2 = self::createDate(
            $gregorian2['year'],
            $gregorian2['month'],
            $gregorian2['day']
        );

        return (int) $date1->diff($date2)->format('%r%a');
    }

    /**
     * Format Ethiopian date.
     */
    public static function format(
        int $year,
        int $month,
        int $day,
        string $lang = 'en'
    ): string {
        return sprintf(
            '%s %d, %d',
            self::monthName($month, $lang),
            $day,
            $year
        );
    }

    /**
     * Get Ethiopian month name.
     */
    public static function monthName(
        int $month,
        string $lang = 'en'
    ): string {
        $months = $lang === 'am'
            ? self::MONTHS_AM
            : self::MONTHS_EN;

        return $months[$month] ?? '';
    }

    /**
     * Get day of week name.
     *
     * 0 = Sunday
     * 1 = Monday
     * ...
     * 6 = Saturday
     */
    public static function dayOfWeekName(
        int $dayOfWeek,
        string $lang = 'en'
    ): string {
        return self::DAYS_OF_WEEK[$dayOfWeek][$lang] ?? '';
    }

    /**
     * Keep backward compatibility with the previous method name.
     */
    public static function DAY_OF_WEEK_NAME(
        int $dayOfWeek,
        string $lang = 'en'
    ): string {
        return self::dayOfWeekName(
            $dayOfWeek,
            $lang
        );
    }

    /**
     * Get all Ethiopian months.
     */
    public static function months(
        string $lang = 'en'
    ): array {
        return $lang === 'am'
            ? self::MONTHS_AM
            : self::MONTHS_EN;
    }

    /**
     * Ethiopian leap year.
     *
     * Ethiopian years divisible by 4 with remainder 3
     * have six days in Pagume.
     */
    public static function isLeapYear(int $year): bool
    {
        return ($year % 4) === 3;
    }

    /**
     * Get number of days in an Ethiopian month.
     */
    public static function daysInMonth(
        int $year,
        int $month
    ): int {
        if ($month < 1 || $month > 13) {
            throw new InvalidArgumentException(
                'Ethiopian month must be between 1 and 13.'
            );
        }

        if ($month === 13) {
            return self::isLeapYear($year) ? 6 : 5;
        }

        return 30;
    }

    /**
     * Get Ethiopian New Year Gregorian date.
     *
     * Examples:
     *
     * Ethiopian 2019-01-01
     * => Gregorian 2026-09-11
     *
     * Ethiopian 2016-01-01
     * => Gregorian 2023-09-12
     *
     * The September 12 case occurs when the following
     * Gregorian year is a leap year.
     */
    private static function ethiopianNewYearDate(
        int $ethiopianYear
    ): DateTimeImmutable {
        if ($ethiopianYear < 1) {
            throw new InvalidArgumentException(
                'Ethiopian year must be greater than zero.'
            );
        }

        $gregorianYear = $ethiopianYear + 7;

        /*
         * Example:
         *
         * Ethiopian 2019
         * Gregorian year = 2026
         *
         * 2027 is not leap
         * => September 11, 2026
         *
         * Ethiopian 2016
         * Gregorian year = 2023
         *
         * 2024 is leap
         * => September 12, 2023
         */
        $followingGregorianYear = $gregorianYear + 1;

        $newYearDay = self::isGregorianLeapYear(
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

    /**
     * Check Gregorian leap year.
     */
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

    /**
     * Create a timezone-aware Gregorian date.
     */
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
            new DateTimeZone(self::TIMEZONE)
        );
    }

    /**
     * Calculate difference between two dates.
     */
    private static function daysBetween(
        DateTimeImmutable $from,
        DateTimeImmutable $to
    ): int {
        return (int) $from
            ->diff($to)
            ->format('%r%a');
    }

    /**
     * Validate Gregorian date.
     */
    private static function validateGregorianDate(
        int $year,
        int $month,
        int $day
    ): void {
        if (!checkdate($month, $day, $year)) {
            throw new InvalidArgumentException(
                'Invalid Gregorian date.'
            );
        }
    }

    /**
     * Validate Ethiopian date.
     */
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

        if ($month < 1 || $month > 13) {
            throw new InvalidArgumentException(
                'Ethiopian month must be between 1 and 13.'
            );
        }

        $maximumDay = self::daysInMonth(
            $year,
            $month
        );

        if ($day < 1 || $day > $maximumDay) {
            throw new InvalidArgumentException(
                'Invalid Ethiopian day for given month/year.'
            );
        }
    }
}