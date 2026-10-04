<?php

declare(strict_types=1);

class AuditLogger
{
    public static function log(
        mysqli $conn,
        string $action,
        string $description,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): bool {
        $userId = isset($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;

        $userName = isset($_SESSION['full_name'])
            ? (string) $_SESSION['full_name']
            : null;

        $userRole = isset($_SESSION['role'])
            ? strtolower((string) $_SESSION['role'])
            : null;

        /*
        |--------------------------------------------------------------------------
        | IP Address
        |--------------------------------------------------------------------------
        */

        $ipAddress = self::getIpAddress();

        /*
        |--------------------------------------------------------------------------
        | User Agent
        |--------------------------------------------------------------------------
        */

        $userAgent = trim(
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
        );

        if ($userAgent === '') {
            $userAgent = null;
        }

        /*
        |--------------------------------------------------------------------------
        | Device / Browser / Operating System
        |--------------------------------------------------------------------------
        */

        $deviceType = self::detectDeviceType($userAgent);

        $browser = self::detectBrowser($userAgent);

        $operatingSystem = self::detectOperatingSystem($userAgent);

        /*
        |--------------------------------------------------------------------------
        | JSON Values
        |--------------------------------------------------------------------------
        */

        $oldJson = $oldValues !== null
            ? json_encode(
                $oldValues,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : null;

        $newJson = $newValues !== null
            ? json_encode(
                $newValues,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : null;

        /*
        |--------------------------------------------------------------------------
        | Insert Audit Log
        |--------------------------------------------------------------------------
        */

        $sql = "
            INSERT INTO audit_logs (
                user_id,
                user_name,
                user_role,
                action,
                description,
                target_type,
                target_id,
                old_values,
                new_values,
                ip_address,
                device_type,
                browser,
                operating_system,
                user_agent
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'isssssssssssss',
            $userId,
            $userName,
            $userRole,
            $action,
            $description,
            $targetType,
            $targetId,
            $oldJson,
            $newJson,
            $ipAddress,
            $deviceType,
            $browser,
            $operatingSystem,
            $userAgent
        );

        $success = $stmt->execute();

        $stmt->close();

        return $success;
    }

    /*
    |--------------------------------------------------------------------------
    | Get Client IP Address
    |--------------------------------------------------------------------------
    */

    private static function getIpAddress(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $ip = trim((string) $ip);

        if ($ip === '') {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Detect Device Type
    |--------------------------------------------------------------------------
    */

    private static function detectDeviceType(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        if (
            preg_match(
                '/tablet|ipad|playbook|silk/i',
                $userAgent
            )
        ) {
            return 'Tablet';
        }

        if (
            preg_match(
                '/mobile|android|iphone|ipod|blackberry|iemobile|opera mini/i',
                $userAgent
            )
        ) {
            return 'Mobile';
        }

        if (
            preg_match(
                '/windows|macintosh|linux|x11|cros/i',
                $userAgent
            )
        ) {
            return 'Desktop';
        }

        return 'Unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | Detect Browser
    |--------------------------------------------------------------------------
    */

    private static function detectBrowser(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        if (preg_match('/Edg\/([0-9.]+)/i', $userAgent, $matches)) {
            return 'Microsoft Edge ' . $matches[1];
        }

        if (preg_match('/OPR\/([0-9.]+)/i', $userAgent, $matches)) {
            return 'Opera ' . $matches[1];
        }

        if (
            preg_match(
                '/SamsungBrowser\/([0-9.]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Samsung Internet ' . $matches[1];
        }

        if (
            preg_match(
                '/CriOS\/([0-9.]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Chrome ' . $matches[1];
        }

        if (
            preg_match(
                '/FxiOS\/([0-9.]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Firefox ' . $matches[1];
        }

        if (
            preg_match(
                '/Chrome\/([0-9.]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Chrome ' . $matches[1];
        }

        if (
            preg_match(
                '/Firefox\/([0-9.]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Firefox ' . $matches[1];
        }

        if (
            preg_match(
                '/Version\/([0-9.]+).*Safari/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Safari ' . $matches[1];
        }

        if (preg_match('/MSIE\s([0-9.]+)/i', $userAgent, $matches)) {
            return 'Internet Explorer ' . $matches[1];
        }

        if (stripos($userAgent, 'Dart/') !== false) {
            return 'BKHS Flutter App';
        }

        return 'Unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | Detect Operating System
    |--------------------------------------------------------------------------
    */

    private static function detectOperatingSystem(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown';
        }

        if (
            preg_match(
                '/Windows NT 10\.0/i',
                $userAgent
            )
        ) {
            return 'Windows 10/11';
        }

        if (
            preg_match(
                '/Windows NT 6\.3/i',
                $userAgent
            )
        ) {
            return 'Windows 8.1';
        }

        if (
            preg_match(
                '/Windows NT 6\.2/i',
                $userAgent
            )
        ) {
            return 'Windows 8';
        }

        if (
            preg_match(
                '/Windows NT 6\.1/i',
                $userAgent
            )
        ) {
            return 'Windows 7';
        }

        if (
            preg_match(
                '/Android\s([0-9.]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'Android ' . $matches[1];
        }

        if (
            preg_match(
                '/iPhone OS ([0-9_]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'iOS ' . str_replace(
                '_',
                '.',
                $matches[1]
            );
        }

        if (
            preg_match(
                '/iPad.*OS ([0-9_]+)/i',
                $userAgent,
                $matches
            )
        ) {
            return 'iPadOS ' . str_replace(
                '_',
                '.',
                $matches[1]
            );
        }

        if (preg_match('/Mac OS X/i', $userAgent)) {
            return 'macOS';
        }

        if (preg_match('/CrOS/i', $userAgent)) {
            return 'ChromeOS';
        }

        if (preg_match('/Linux/i', $userAgent)) {
            return 'Linux';
        }

        return 'Unknown';
    }
}
