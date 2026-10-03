<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Principal Attendance Helpers
|--------------------------------------------------------------------------
*/

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Normalize a Gregorian date.
 */
function normalizeDate(?string $date): ?string
{
    $date = trim((string) $date);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    [$year, $month, $day] = array_map(
        'intval',
        explode('-', $date)
    );

    if (!checkdate($month, $day, $year)) {
        return null;
    }

    return sprintf(
        '%04d-%02d-%02d',
        $year,
        $month,
        $day
    );
}

/**
 * Convert Gregorian date to Ethiopian parts.
 */
function gregorianToEthiopianDate(string $date): array
{
    $normalized = normalizeDate($date);

    if ($normalized === null) {
        throw new InvalidArgumentException(
            'Invalid Gregorian date.'
        );
    }

    [$year, $month, $day] = array_map(
        'intval',
        explode('-', $normalized)
    );

    return EthiopianCalendar::gregorianToEthiopian(
        $year,
        $month,
        $day
    );
}

/**
 * Convert Ethiopian date to Gregorian Y-m-d.
 */
function ethiopianToGregorianDate(
    int $year,
    int $month,
    int $day
): string {
    return EthiopianCalendar::toGregorian(
        $year,
        $month,
        $day
    );
}

/**
 * Format Ethiopian date.
 */
function ethiopianDateLabel(
    int $year,
    int $month,
    int $day
): string {
    return EthiopianCalendar::format(
        $year,
        $month,
        $day
    );
}

/**
 * Current Ethiopian date.
 */
function getEthiopianToday(): array
{
    return EthiopianCalendar::today();
}

/**
 * Redirect with a flash-style query message.
 */
function redirectWithMessage(
    string $type,
    string $message,
    array $params = []
): never {
    $params['message_type'] = $type;
    $params['message'] = $message;

    header(
        'Location: attendance.php?' .
        http_build_query($params)
    );

    exit;
}

/**
 * Build a URL while preserving attendance filters.
 */
function attendanceUrl(array $params = []): string
{
    return '?' . http_build_query($params);
}

/**
 * Validate attendance status.
 */
function validAttendanceStatus(string $status): bool
{
    return in_array(
        $status,
        [
            'Present',
            'Absent',
            'Late',
            'Excused'
        ],
        true
    );
}

/**
 * Return status CSS class.
 */
function attendanceStatusClass(
    ?string $status
): string {
    return match ($status) {
        'Present' => 'status-present',
        'Absent' => 'status-absent',
        'Late' => 'status-late',
        'Excused' => 'status-excused',
        default => 'status-empty'
    };
}

/**
 * Return short attendance symbol.
 */
function attendanceStatusShort(
    ?string $status
): string {
    return match ($status) {
        'Present' => 'P',
        'Absent' => 'A',
        'Late' => 'L',
        'Excused' => 'E',
        default => '—'
    };
}

/**
 * Return Ethiopian month name.
 */
function ethiopianMonthName(int $month): string
{
    return EthiopianCalendar::monthName($month);
}