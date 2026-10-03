<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Teacher Authentication
|--------------------------------------------------------------------------
*/
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/
require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/
function h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Page State
|--------------------------------------------------------------------------
*/
$pageError = '';

$selectedClass = isset($_GET['class'])
    ? trim((string) $_GET['class'])
    : '';

$latest = isset($_GET['latest'])
    ? (int) $_GET['latest']
    : 5;

/*
|--------------------------------------------------------------------------
| Limit Latest Attendance
|--------------------------------------------------------------------------
*/
if ($latest < 1) {
    $latest = 1;
}

if ($latest > 30) {
    $latest = 30;
}

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/
$timezone = new DateTimeZone('Africa/Addis_Ababa');

$now = new DateTimeImmutable(
    'now',
    $timezone
);

$todayGregorian = $now->format('Y-m-d');

/*
|--------------------------------------------------------------------------
| Teacher Profile
|--------------------------------------------------------------------------
*/
$teacherName = $_SESSION['full_name'] ?? 'Teacher';

$teacherEmail = '';
$teacherPhone = '';

try {

    $profileStmt = $conn->prepare(
        "SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            t.id AS teacher_id
        FROM users u
        LEFT JOIN teachers t
            ON t.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'teacher'
          AND u.is_deleted = 0
        LIMIT 1"
    );

    if ($profileStmt) {

        $profileStmt->bind_param(
            'i',
            $teacherUserId
        );

        $profileStmt->execute();

        $profileResult =
            $profileStmt->get_result();

        if (
            $profileResult &&
            ($profileRow = $profileResult->fetch_assoc())
        ) {

            $teacherName =
                $profileRow['full_name']
                ?? $teacherName;

            $teacherEmail =
                $profileRow['email']
                ?? '';

            $teacherPhone =
                $profileRow['phone']
                ?? '';
        }

        $profileStmt->close();
    }

} catch (Throwable $e) {

    // Keep page working if profile data cannot be loaded.
}

/*
|--------------------------------------------------------------------------
| Teacher Photo
|--------------------------------------------------------------------------
*/
$teacherPhoto =
    '../../assets/images/default-avatar.png';

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/
$activeAcademicYear = null;

try {

    $yearStmt = $conn->prepare(
        "SELECT
            id,
            name,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day,
            status
        FROM academic_years
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1"
    );

    if ($yearStmt) {

        $yearStmt->execute();

        $yearResult =
            $yearStmt->get_result();

        if ($yearResult) {

            $activeAcademicYear =
                $yearResult->fetch_assoc()
                ?: null;
        }

        $yearStmt->close();
    }

} catch (Throwable $e) {

    $activeAcademicYear = null;
}

if (!$activeAcademicYear) {

    $pageError =
        'No active academic year was found.';
}

$academicYearName =
    $activeAcademicYear['name']
    ?? '';

$academicYearId =
    $activeAcademicYear
        ? (int) $activeAcademicYear['id']
        : null;

/*
|--------------------------------------------------------------------------
| Ethiopian Today
|--------------------------------------------------------------------------
*/
$todayEth =
    EthiopianCalendar::today();

$todayEthFormatted =
    EthiopianCalendar::format(
        $todayEth['year'],
        $todayEth['month'],
        $todayEth['day'],
        'en'
    );

/*
|--------------------------------------------------------------------------
| Get Teacher Homeroom Classes
|--------------------------------------------------------------------------
*/
$homeroomClasses = [];

if ($activeAcademicYear) {

    try {

        $classStmt = $conn->prepare(
            "SELECT
                hta.id,
                hta.grade,
                hta.section,
                g.name AS grade_name,
                sec.name AS section_name,
                sec.code AS section_code
            FROM homeroom_teacher_assignments hta
            LEFT JOIN grades g
                ON g.grade_number = hta.grade
            LEFT JOIN sections sec
                ON sec.code = hta.section
            WHERE hta.teacher_user_id = ?
              AND hta.academic_year = ?
              AND hta.is_active = 1
            ORDER BY hta.grade, hta.section"
        );

        if ($classStmt) {

            $classStmt->bind_param(
                'is',
                $teacherUserId,
                $academicYearName
            );

            $classStmt->execute();

            $classResult =
                $classStmt->get_result();

            while (
                $classResult &&
                ($row = $classResult->fetch_assoc())
            ) {

                $homeroomClasses[] = $row;
            }

            $classStmt->close();
        }

    } catch (Throwable $e) {

        $pageError =
            'Unable to load your homeroom classes.';
    }
}

/*
|--------------------------------------------------------------------------
| Automatically Select First Class
|--------------------------------------------------------------------------
*/
if (
    $selectedClass === '' &&
    !empty($homeroomClasses)
) {

    $selectedClass =
        (string) $homeroomClasses[0]['grade']
        . '-'
        . (string) $homeroomClasses[0]['section'];
}

/*
|--------------------------------------------------------------------------
| Resolve Selected Class
|--------------------------------------------------------------------------
*/
$selectedGrade = null;
$selectedSection = null;

if ($selectedClass !== '') {

    $parts =
        explode(
            '-',
            $selectedClass,
            2
        );

    if (count($parts) === 2) {

        $selectedGrade =
            (int) $parts[0];

        $selectedSection =
            strtoupper(
                trim($parts[1])
            );
    }
}

/*
|--------------------------------------------------------------------------
| Verify Selected Class Belongs To Teacher
|--------------------------------------------------------------------------
*/
$classIsValid = false;

if (
    $selectedGrade !== null &&
    $selectedSection !== null &&
    $activeAcademicYear
) {

    foreach ($homeroomClasses as $class) {

        if (
            (int) $class['grade'] === $selectedGrade &&
            strtoupper(
                (string) $class['section']
            ) === $selectedSection
        ) {

            $classIsValid = true;

            break;
        }
    }
}

if (
    $selectedClass !== '' &&
    !$classIsValid &&
    !empty($homeroomClasses)
) {

    $selectedGrade = null;
    $selectedSection = null;
    $selectedClass = '';

    $pageError =
        'The selected class is not assigned to you.';
}

/*
|--------------------------------------------------------------------------
| Resolve Grade ID + Section ID
|--------------------------------------------------------------------------
*/
$gradeId = null;
$sectionId = null;

if (
    $classIsValid &&
    $selectedGrade !== null &&
    $selectedSection !== null
) {

    try {

        $resolveStmt = $conn->prepare(
            "SELECT
                g.id AS grade_id,
                sec.id AS section_id
            FROM grades g
            INNER JOIN sections sec
                ON sec.code = ?
            WHERE g.grade_number = ?
            LIMIT 1"
        );

        if ($resolveStmt) {

            $resolveStmt->bind_param(
                'si',
                $selectedSection,
                $selectedGrade
            );

            $resolveStmt->execute();

            $resolveResult =
                $resolveStmt->get_result();

            if (
                $resolveResult &&
                ($resolveRow =
                    $resolveResult->fetch_assoc())
            ) {

                $gradeId =
                    (int) $resolveRow['grade_id'];

                $sectionId =
                    (int) $resolveRow['section_id'];
            }

            $resolveStmt->close();
        }

    } catch (Throwable $e) {

        $pageError =
            'Unable to resolve the selected class.';
    }
}

/*
|--------------------------------------------------------------------------
| Selected Class Display Name
|--------------------------------------------------------------------------
*/
$selectedClassName = '';

foreach ($homeroomClasses as $class) {

    if (
        $selectedGrade !== null &&
        $selectedSection !== null &&
        (int) $class['grade'] === $selectedGrade &&
        strtoupper(
            (string) $class['section']
        ) === $selectedSection
    ) {

        $selectedClassName =
            ($class['grade_name']
                ?: 'Grade ' . $class['grade'])
            . ' - '
            . ($class['section_name']
                ?: 'Section ' . $class['section']);

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Attendance Dates
|--------------------------------------------------------------------------
|
| student_attendance.attendance_date is Gregorian.
| EthiopianCalendar is used for display.
|--------------------------------------------------------------------------
*/
$attendanceDates = [];

if (
    $classIsValid &&
    $activeAcademicYear &&
    $gradeId !== null &&
    $sectionId !== null
) {

    try {

        /*
        |--------------------------------------------------------------------------
        | latest is already safely limited to 1-30.
        | It can therefore be placed directly into LIMIT.
        |--------------------------------------------------------------------------
        */
        $dateStmt = $conn->prepare(
            "SELECT DISTINCT
                attendance_date
            FROM student_attendance
            WHERE academic_year_id = ?
              AND grade_id = ?
              AND section_id = ?
              AND attendance_date <= ?
            ORDER BY attendance_date DESC
            LIMIT {$latest}"
        );

        if ($dateStmt) {

            $dateStmt->bind_param(
                'iiis',
                $academicYearId,
                $gradeId,
                $sectionId,
                $todayGregorian
            );

            $dateStmt->execute();

            $dateResult =
                $dateStmt->get_result();

            while (
                $dateResult &&
                ($row = $dateResult->fetch_assoc())
            ) {

                $attendanceDates[] =
                    $row['attendance_date'];
            }

            $dateStmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | Display oldest -> newest
        |--------------------------------------------------------------------------
        */
        $attendanceDates =
            array_reverse(
                $attendanceDates
            );

    } catch (Throwable $e) {

        $pageError =
            'Unable to load attendance dates.';
    }
}

/*
|--------------------------------------------------------------------------
| Students
|--------------------------------------------------------------------------
|
| Attendance is tied to student_registrations.
| Therefore registration_id is kept with every student.
|--------------------------------------------------------------------------
*/
$students = [];

if (
    $classIsValid &&
    $activeAcademicYear &&
    $gradeId !== null &&
    $sectionId !== null
) {

    try {

        $studentStmt = $conn->prepare(
            "SELECT
                sr.id AS registration_id,
                s.id AS student_id,
                s.student_code,
                s.full_name
            FROM student_registrations sr
            INNER JOIN students s
                ON s.id = sr.student_id
            WHERE sr.academic_year_id = ?
              AND sr.grade_id = ?
              AND sr.section_id = ?
            ORDER BY s.full_name
            LIMIT 5000"
        );

        if ($studentStmt) {

            $studentStmt->bind_param(
                'iii',
                $academicYearId,
                $gradeId,
                $sectionId
            );

            $studentStmt->execute();

            $studentResult =
                $studentStmt->get_result();

            while (
                $studentResult &&
                ($row = $studentResult->fetch_assoc())
            ) {

                $students[] = $row;
            }

            $studentStmt->close();
        }

    } catch (Throwable $e) {

        $pageError =
            'Unable to load students.';
    }
}

/*
|--------------------------------------------------------------------------
| Attendance Map
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Attendance is mapped by registration_id, not student_id.
|
| This matches the corrected attendance edit workflow:
|
| student_attendance.registration_id
|          ↓
| student_registrations.id
|--------------------------------------------------------------------------
*/
$attendanceMap = [];

if (
    !empty($students) &&
    !empty($attendanceDates) &&
    $activeAcademicYear &&
    $gradeId !== null &&
    $sectionId !== null
) {

    try {

        /*
        |--------------------------------------------------------------------------
        | Registration IDs
        |--------------------------------------------------------------------------
        */
        $registrationIds = [];

        foreach ($students as $student) {

            $registrationIds[] =
                (int) $student['registration_id'];
        }

        $registrationIds =
            array_values(
                array_unique(
                    $registrationIds
                )
            );

        if (!empty($registrationIds)) {

            /*
            |--------------------------------------------------------------------------
            | Registration placeholders
            |--------------------------------------------------------------------------
            */
            $registrationPlaceholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($registrationIds),
                        '?'
                    )
                );

            /*
            |--------------------------------------------------------------------------
            | Date placeholders
            |--------------------------------------------------------------------------
            */
            $datePlaceholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($attendanceDates),
                        '?'
                    )
                );

            /*
            |--------------------------------------------------------------------------
            | Attendance Query
            |--------------------------------------------------------------------------
            */
            $sql = "
                SELECT
                    registration_id,
                    student_id,
                    attendance_date,
                    status
                FROM student_attendance
                WHERE academic_year_id = ?
                  AND grade_id = ?
                  AND section_id = ?
                  AND registration_id IN (
                      {$registrationPlaceholders}
                  )
                  AND attendance_date IN (
                      {$datePlaceholders}
                  )
                ORDER BY attendance_date ASC
            ";

            $attendanceStmt =
                $conn->prepare($sql);

            if ($attendanceStmt) {

                $types = 'iii';

                $params = [
                    (int) $academicYearId,
                    (int) $gradeId,
                    (int) $sectionId
                ];

                foreach ($registrationIds as $registrationId) {

                    $types .= 'i';

                    $params[] =
                        $registrationId;
                }

                foreach ($attendanceDates as $date) {

                    $types .= 's';

                    $params[] =
                        $date;
                }

                /*
                |--------------------------------------------------------------------------
                | Dynamic bind_param
                |--------------------------------------------------------------------------
                */
                $bindParams = [];

                $bindParams[] =
                    $types;

                foreach ($params as $key => $value) {

                    $bindParams[] =
                        &$params[$key];
                }

                call_user_func_array(
                    [
                        $attendanceStmt,
                        'bind_param'
                    ],
                    $bindParams
                );

                $attendanceStmt->execute();

                $attendanceResult =
                    $attendanceStmt->get_result();

                while (
                    $attendanceResult &&
                    (
                        $attendanceRow =
                            $attendanceResult->fetch_assoc()
                    )
                ) {

                    $registrationId =
                        (int)
                        $attendanceRow['registration_id'];

                    $attendanceDate =
                        $attendanceRow['attendance_date'];

                    $attendanceMap[
                        $registrationId
                    ][
                        $attendanceDate
                    ] =
                        $attendanceRow['status'];
                }

                $attendanceStmt->close();
            }
        }

    } catch (Throwable $e) {

        $pageError =
            'Unable to load attendance records.';
    }
}

/*
|--------------------------------------------------------------------------
| Ethiopian Date Helper
|--------------------------------------------------------------------------
*/
function formatEthiopianAttendanceDate(
    string $gregorianDate
): string {

    try {

        $eth =
            EthiopianCalendar::fromGregorian(
                $gregorianDate
            );

        return EthiopianCalendar::format(
            $eth['year'],
            $eth['month'],
            $eth['day'],
            'en'
        );

    } catch (Throwable $e) {

        return $gregorianDate;
    }
}

/*
|--------------------------------------------------------------------------
| Status Class
|--------------------------------------------------------------------------
*/
function attendanceStatusClass(
    string $status
): string {

    return match (
        strtolower(
            trim($status)
        )
    ) {

        'present' =>
            'status-present',

        'absent' =>
            'status-absent',

        'late' =>
            'status-late',

        'excused' =>
            'status-excused',

        default =>
            'status-empty',
    };
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

    <meta
        name="description"
        content="Teacher Attendance History - Bole Kale Hiwot School"
    >

    <title>Attendance History | BKHS</title>

    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <!-- Inter -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --text: #1e293b;
            --muted: #64748b;
            --border: #cbd5e1;
            --page-bg: #f8fafc;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            min-height: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--page-bg);
            color: var(--text);
            font-size: 13px;
        }

        a {
            text-decoration: none;
        }

        /* =====================================================
           SIDEBAR
           ===================================================== */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1000;
            display: flex;
            flex-direction: column;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-logo {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 10px;
            background: #fff;
        }

        .sidebar-brand-text {
            line-height: 1.2;
        }

        .sidebar-brand-title {
            font-size: 14px;
            font-weight: 700;
            color: #fff;
        }

        .sidebar-brand-subtitle {
            margin-top: 3px;
            font-size: 10px;
            color: #94a3b8;
        }

        .sidebar-menu {
            flex: 1;
            padding: 16px 12px;
            overflow-y: auto;
        }

        .sidebar-section-title {
            padding: 0 10px;
            margin: 12px 0 8px;
            font-size: 10px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 11px;
            min-height: 42px;
            padding: 0 12px;
            margin-bottom: 4px;
            color: #cbd5e1;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 500;
            transition: .18s ease;
        }

        .sidebar-link i {
            font-size: 16px;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-link.active {
            background: rgba(37, 99, 235, .18);
            color: #fff;
        }

        .sidebar-link.active i {
            color: #60a5fa;
        }

        /* =====================================================
           MAIN
           ===================================================== */

        .main-wrapper {
            margin-left: 260px;
            min-height: 100vh;
        }

        /* =====================================================
           TOPBAR
           ===================================================== */

        .topbar {
            position: sticky;
            top: 0;
            z-index: 900;
            height: 76px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
        }

        .topbar-subtitle {
            margin-top: 3px;
            color: var(--muted);
            font-size: 11px;
        }

        .topbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .today-box {
            text-align: right;
        }

        .today-label {
            font-size: 10px;
            color: var(--muted);
        }

        .today-date {
            font-size: 12px;
            font-weight: 600;
            color: #334155;
        }

        .teacher-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e2e8f0;
        }

        /* =====================================================
           CONTENT
           ===================================================== */

        .page-content {
            padding: 26px 28px 40px;
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }

        .page-title {
            margin: 0;
            font-size: 21px;
            font-weight: 700;
            color: #0f172a;
        }

        .page-description {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        /* =====================================================
           MOBILE DASHBOARD BUTTON
           ===================================================== */

        .mobile-dashboard-btn {
            display: none;
        }

        /* =====================================================
           ATTENDANCE NAVIGATION
           ===================================================== */

        .attendance-navigation {
            width: fit-content;
            max-width: 100%;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 6px;
            display: flex;
            gap: 4px;
            margin-bottom: 18px;
            box-shadow: 0 3px 12px rgba(0,0,0,.03);
        }

        .attendance-nav-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 38px;
            padding: 0 15px;
            border-radius: 8px;
            color: #64748b;
            background: transparent;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
            transition: .18s ease;
        }

        .attendance-nav-link:hover {
            color: var(--primary);
            background: #eff6ff;
        }

        .attendance-nav-link.active {
            background: var(--primary);
            color: #fff;
            box-shadow: 0 4px 10px rgba(37,99,235,.18);
        }

        .attendance-nav-link.active:hover {
            background: var(--primary);
            color: #fff;
        }

        /* =====================================================
           FILTER CARD
           ===================================================== */

        .filter-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 17px;
            margin-bottom: 18px;
            box-shadow: 0 4px 15px rgba(15,23,42,.03);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) 180px auto;
            gap: 12px;
            align-items: end;
        }

        .form-label {
            margin-bottom: 6px;
            color: #475569;
            font-size: 11px;
            font-weight: 600;
        }

        .form-select {
            min-height: 40px;
            border-color: #cbd5e1;
            border-radius: 8px;
            font-size: 12px;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.12);
        }

        .btn-primary {
            min-height: 40px;
            border: 0;
            border-radius: 8px;
            background: var(--primary);
            font-size: 12px;
            font-weight: 600;
            padding: 0 16px;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-export {
            min-height: 40px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #334155;
            font-size: 12px;
            font-weight: 600;
            padding: 0 14px;
        }

        .btn-export:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        /* =====================================================
           INFO BAR
           ===================================================== */

        .attendance-info {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 12px;
        }

        .attendance-info-left {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .info-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 7px;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 11px;
            font-weight: 500;
        }

        .info-badge strong {
            color: #1e293b;
        }

        /* =====================================================
           ALERT
           ===================================================== */

        .page-alert {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 10px;
            padding: 11px 13px;
            font-size: 12px;
            margin-bottom: 15px;
        }

        /* =====================================================
           DESKTOP ATTENDANCE TABLE
           ===================================================== */

        .table-responsive {
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            overflow: auto;
            background: #f8fafc;
        }

        .attendance-table {
            width: 100%;
            min-width: 650px;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
            background: #f8fafc;
        }

        .attendance-table th,
        .attendance-table td {
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            padding: 10px 12px;
            vertical-align: middle;
        }

        .attendance-table th:last-child,
        .attendance-table td:last-child {
            border-right: 0;
        }

        .attendance-table thead th {
            background: #e2e8f0;
            color: #334155;
            font-size: 10px;
            font-weight: 700;
            text-align: center;
            white-space: nowrap;
            border-top: 1px solid #cbd5e1;
        }

        .attendance-table tbody td {
            background: #f8fafc;
            color: #334155;
            font-size: 12px;
        }

        .attendance-table tbody tr:nth-child(even) td {
            background: #f1f5f9;
        }

        .attendance-table tbody tr:hover td {
            background: #eaf2ff;
        }

        .attendance-table .student-column {
            width: 180px;
            min-width: 180px;
            max-width: 180px;
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .attendance-table th.student-column,
        .attendance-table td.student-column {
            position: sticky;
            left: 0;
            z-index: 3;
            border-right: 2px solid #94a3b8;
        }

        .attendance-table thead th.student-column {
            z-index: 5;
            background: #dbe4f0;
        }

        .attendance-table tbody td.student-column {
            background: #f8fafc;
        }

        .attendance-table tbody tr:nth-child(even) td.student-column {
            background: #f1f5f9;
        }

        .attendance-table tbody tr:hover td.student-column {
            background: #eaf2ff;
        }

        .attendance-table .status-cell {
            width: 75px;
            min-width: 75px;
            text-align: center;
        }

        /* =====================================================
           STATUS COLORS
           ===================================================== */

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 54px;
            min-height: 24px;
            padding: 0 7px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 700;
        }

        .status-present {
            background: #dcfce7;
            color: #166534;
        }

        .status-absent {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-late {
            background: #fef3c7;
            color: #92400e;
        }

        .status-excused {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-empty {
            color: #94a3b8;
        }

        /* =====================================================
           MOBILE TABLE
           ===================================================== */

        .mobile-history {
            display: none;
        }

        .mobile-attendance-table-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #f8fafc;
        }

        .mobile-attendance-table {
            width: 100%;
            min-width: 500px;
            margin: 0;
            border-collapse: separate;
            border-spacing: 0;
            background: #f8fafc;
        }

        .mobile-attendance-table th,
        .mobile-attendance-table td {
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            padding: 9px 10px;
            vertical-align: middle;
        }

        .mobile-attendance-table th:last-child,
        .mobile-attendance-table td:last-child {
            border-right: 0;
        }

        .mobile-attendance-table thead th {
            background: #e2e8f0;
            color: #334155;
            font-size: 9px;
            font-weight: 700;
            text-align: center;
            white-space: nowrap;
            border-top: 1px solid #cbd5e1;
        }

        .mobile-attendance-table tbody td {
            background: #f8fafc;
            font-size: 11px;
        }

        .mobile-attendance-table tbody tr:nth-child(even) td {
            background: #f1f5f9;
        }

        .mobile-attendance-table tbody tr:hover td {
            background: #eaf2ff;
        }

        .mobile-attendance-table th.student-column,
        .mobile-attendance-table td.student-column {
            position: sticky;
            left: 0;
            width: 155px;
            min-width: 155px;
            max-width: 155px;
            text-align: left;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            z-index: 3;
            border-right: 2px solid #94a3b8;
        }

        .mobile-attendance-table thead th.student-column {
            background: #dbe4f0;
            z-index: 5;
        }

        .mobile-attendance-table tbody td.student-column {
            background: #f8fafc;
        }

        .mobile-attendance-table tbody tr:nth-child(even) td.student-column {
            background: #f1f5f9;
        }

        .mobile-attendance-table tbody tr:hover td.student-column {
            background: #eaf2ff;
        }

        .mobile-attendance-table .status-cell {
            width: 65px;
            min-width: 65px;
            text-align: center;
        }

        /* =====================================================
           EMPTY STATE
           ===================================================== */

        .empty-state {
            padding: 40px 20px;
            text-align: center;
            color: #64748b;
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
        }

        .empty-state i {
            display: block;
            font-size: 34px;
            margin-bottom: 10px;
            color: #94a3b8;
        }

        .empty-state-title {
            color: #334155;
            font-weight: 700;
            font-size: 14px;
        }

        .empty-state-text {
            margin-top: 5px;
            font-size: 11px;
        }

        /* =====================================================
           MOBILE BOTTOM NAVIGATION
           ===================================================== */

        .mobile-bottom-nav {
            display: none;
        }

        /* =====================================================
           RESPONSIVE
           ===================================================== */

        @media (max-width: 991px) {

            body {
                padding-bottom: 72px;
            }

            .sidebar {
                display: none;
            }

            .main-wrapper {
                margin-left: 0;
            }

            .topbar {
                height: 64px;
                padding: 0 15px;
            }

            .topbar-title {
                font-size: 15px;
            }

            .topbar-subtitle {
                font-size: 10px;
            }

            .today-box {
                display: none;
            }

            .teacher-avatar {
                width: 36px;
                height: 36px;
            }

            .page-content {
                padding: 18px 14px 30px;
            }

            .page-heading {
                align-items: center;
                margin-bottom: 15px;
            }

            .page-title {
                font-size: 18px;
            }

            .page-description {
                font-size: 10px;
            }

            .mobile-dashboard-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
                min-height: 34px;
                padding: 0 10px;
                background: #fff;
                color: #334155;
                border: 1px solid #cbd5e1;
                border-radius: 8px;
                font-size: 10px;
                font-weight: 600;
                white-space: nowrap;
            }

            .mobile-dashboard-btn:hover {
                background: #f8fafc;
                color: var(--primary);
            }

            .attendance-navigation {
                width: 100%;
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 4px;
                margin-bottom: 14px;
            }

            .attendance-nav-link {
                min-height: 40px;
                padding: 0 5px;
                gap: 5px;
                font-size: 10px;
            }

            .attendance-nav-link i {
                font-size: 13px;
            }

            .filter-card {
                padding: 13px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .btn-primary,
            .btn-export {
                width: 100%;
            }

            .attendance-info {
                align-items: flex-start;
                flex-direction: column;
            }

            .table-responsive {
                display: none;
            }

            .mobile-history {
                display: block;
            }

            .mobile-attendance-table-wrapper {
                border-radius: 10px;
            }

            .mobile-attendance-table {
                min-width: 500px;
            }

            .mobile-attendance-table th,
            .mobile-attendance-table td {
                padding: 8px 9px;
            }

            .mobile-attendance-table th.student-column,
            .mobile-attendance-table td.student-column {
                width: 155px;
                min-width: 155px;
                max-width: 155px;
            }

            /* =================================================
               MOBILE BOTTOM NAVIGATION
               ================================================= */

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 64px;
                background: rgba(255,255,255,.97);
                border-top: 1px solid #e2e8f0;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                align-items: center;
                z-index: 1200;
                padding-bottom: env(safe-area-inset-bottom);
                box-shadow: 0 -4px 18px rgba(15,23,42,.08);
            }

            .mobile-bottom-link {
                height: 64px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                color: #64748b;
                font-size: 9px;
                font-weight: 600;
            }

            .mobile-bottom-link i {
                font-size: 17px;
            }

            .mobile-bottom-link:hover,
            .mobile-bottom-link.active {
                color: var(--primary);
            }
        }

        @media (max-width: 480px) {

            .page-content {
                padding-left: 10px;
                padding-right: 10px;
            }

            .page-title {
                font-size: 17px;
            }

            .attendance-nav-link {
                gap: 3px;
                padding: 0 3px;
                font-size: 9px;
            }

            .attendance-nav-link i {
                font-size: 12px;
            }

            .mobile-attendance-table {
                min-width: 470px;
            }

            .mobile-attendance-table th.student-column,
            .mobile-attendance-table td.student-column {
                width: 145px;
                min-width: 145px;
                max-width: 145px;
            }
        }

    </style>

</head>

<body>

    <!-- =====================================================
         DESKTOP SIDEBAR
         ===================================================== -->

    <aside class="sidebar">

        <div class="sidebar-brand">

            <img
                src="../../public/image/logo.webp"
                alt="BKHS Logo"
                class="sidebar-logo"
            >

            <div class="sidebar-brand-text">

                <div class="sidebar-brand-title">
                    BKHS
                </div>

                <div class="sidebar-brand-subtitle">
                    Teacher Portal
                </div>

            </div>

        </div>

        <div class="sidebar-menu">

            <div class="sidebar-section-title">
                Main
            </div>

            <a
                href="../dashboard.php"
                class="sidebar-link"
            >
                <i class="bi bi-grid-1x2"></i>
                <span>Dashboard</span>
            </a>

            <a
                href="../subjects.php"
                class="sidebar-link"
            >
                <i class="bi bi-book"></i>
                <span>My Subjects</span>
            </a>

            <a
                href="../classes.php"
                class="sidebar-link"
            >
                <i class="bi bi-people"></i>
                <span>My Classes</span>
            </a>

            <div class="sidebar-section-title">
                Academic
            </div>

            <a
                href="../result.php"
                class="sidebar-link"
            >
                <i class="bi bi-bar-chart"></i>
                <span>Results</span>
            </a>

            <a
                href="../daily-attendance.php"
                class="sidebar-link active"
            >
                <i class="bi bi-calendar-check"></i>
                <span>Daily Attendance</span>
            </a>

            <a
                href="../subject-attendance.php"
                class="sidebar-link"
            >
                <i class="bi bi-journal-check"></i>
                <span>Subject Attendance</span>
            </a>

            <a
                href="../homework.php"
                class="sidebar-link"
            >
                <i class="bi bi-journal-text"></i>
                <span>Homework</span>
            </a>

            <div class="sidebar-section-title">
                Communication
            </div>

            <a
                href="../announcements.php"
                class="sidebar-link"
            >
                <i class="bi bi-megaphone"></i>
                <span>Announcements</span>
            </a>

            <div class="sidebar-section-title">
                Other
            </div>

            <a
                href="../roster.php"
                class="sidebar-link"
            >
                <i class="bi bi-card-list"></i>
                <span>Roster</span>
            </a>

            <a
                href="../profile.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-circle"></i>
                <span>Profile</span>
            </a>

            <a
                href="../../auth/logout.php"
                class="sidebar-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </div>

    </aside>


    <!-- =====================================================
         MAIN WRAPPER
         ===================================================== -->

    <div class="main-wrapper">

        <header class="topbar">

            <div></div>

            <div class="topbar-right">

                <div class="today-box">

                    <div class="today-label">
                        Ethiopian Date
                    </div>

                    <div class="today-date">
                        <?= h($todayEthFormatted) ?>
                    </div>

                </div>

                <img
                    src="<?= h($teacherPhoto) ?>"
                    alt="Teacher"
                    class="teacher-avatar"
                >

            </div>

        </header>


        <!-- =================================================
             PAGE CONTENT
             ================================================= -->

        <main class="page-content">

            <!-- PAGE HEADING -->

            <div class="page-heading">

                <div>

                    <h2 class="page-title">
                        Attendance History
                    </h2>

                    <p class="page-description">
                        View the latest daily attendance records for your homeroom classes.
                    </p>

                </div>

                <a
                    href="../dashboard.php"
                    class="mobile-dashboard-btn"
                >
                    <i class="bi bi-grid-1x2"></i>
                    Dashboard
                </a>

            </div>


            <!-- =================================================
                 ATTENDANCE NAVIGATION
                 ================================================= -->

            <div class="attendance-navigation">

                <a
                    href="../daily-attendance.php"
                    class="attendance-nav-link"
                >
                    <i class="bi bi-calendar-plus"></i>
                    <span>Create Attendance</span>
                </a>

                <a
                    href="history.php"
                    class="attendance-nav-link active"
                >
                    <i class="bi bi-clock-history"></i>
                    <span>Attendance History</span>
                </a>

                <a
                    href="edit.php"
                    class="attendance-nav-link"
                >
                    <i class="bi bi-pencil-square"></i>
                    <span>Edit Attendance</span>
                </a>

            </div>


            <!-- ERROR -->

            <?php if ($pageError !== ''): ?>

                <div class="page-alert">

                    <i class="bi bi-exclamation-circle me-1"></i>

                    <?= h($pageError) ?>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 FILTER
                 ================================================= -->

            <div class="filter-card">

                <form
                    method="get"
                    action="history.php"
                >

                    <div class="filter-grid">

                        <div>

                            <label
                                for="class"
                                class="form-label"
                            >
                                Class
                            </label>

                            <select
                                name="class"
                                id="class"
                                class="form-select"
                            >

                                <?php if (empty($homeroomClasses)): ?>

                                    <option value="">
                                        No assigned class
                                    </option>

                                <?php else: ?>

                                    <?php foreach ($homeroomClasses as $class): ?>

                                        <?php

                                        $classValue =
                                            (string) $class['grade']
                                            . '-'
                                            . (string) $class['section'];

                                        $classLabel =
                                            ($class['grade_name']
                                                ?: 'Grade ' . $class['grade'])
                                            . ' - '
                                            . ($class['section_name']
                                                ?: 'Section ' . $class['section']);

                                        ?>

                                        <option
                                            value="<?= h($classValue) ?>"
                                            <?= $selectedClass === $classValue ? 'selected' : '' ?>
                                        >
                                            <?= h($classLabel) ?>
                                        </option>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </select>

                        </div>


                        <div>

                            <label
                                for="latest"
                                class="form-label"
                            >
                                Latest Attendance
                            </label>

                            <select
                                name="latest"
                                id="latest"
                                class="form-select"
                                onchange="this.form.submit()"
                            >

                                <?php for ($i = 1; $i <= 30; $i++): ?>

                                    <option
                                        value="<?= $i ?>"
                                        <?= $latest === $i ? 'selected' : '' ?>
                                    >
                                        Latest <?= $i ?>
                                    </option>

                                <?php endfor; ?>

                            </select>

                        </div>


                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1"
                            >
                                <i class="bi bi-funnel me-1"></i>
                                Apply
                            </button>

                            <?php if ($classIsValid): ?>

                                <a
                                    href="export.php?<?= http_build_query([
                                        'class' => $selectedClass,
                                        'latest' => $latest
                                    ]) ?>"
                                    class="btn-export d-inline-flex align-items-center justify-content-center"
                                    title="Export attendance"
                                >
                                    <i class="bi bi-download"></i>
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                </form>

            </div>


            <!-- =================================================
                 INFORMATION
                 ================================================= -->

            <?php if ($classIsValid): ?>

                <div class="attendance-info">

                    <div class="attendance-info-left">

                        <div class="info-badge">

                            <i class="bi bi-people"></i>

                            <strong>
                                <?= h($selectedClassName) ?>
                            </strong>

                        </div>

                        <div class="info-badge">

                            <i class="bi bi-calendar3"></i>

                            <?= $latest ?>
                            attendance day<?= $latest !== 1 ? 's' : '' ?>

                        </div>

                        <div class="info-badge">

                            <i class="bi bi-person"></i>

                            <?= count($students) ?>
                            student<?= count($students) !== 1 ? 's' : '' ?>

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 DESKTOP TABLE
                 ================================================= -->

            <?php if (
                $classIsValid &&
                !empty($students) &&
                !empty($attendanceDates)
            ): ?>

                <div class="table-responsive">

                    <table class="attendance-table">

                        <thead>

                            <tr>

                                <th class="student-column">
                                    Student Name
                                </th>

                                <?php foreach ($attendanceDates as $date): ?>

                                    <?php
                                    $ethiopianDate =
                                        formatEthiopianAttendanceDate($date);
                                    ?>

                                    <th title="<?= h($date) ?>">
                                        <?= h($ethiopianDate) ?>
                                    </th>

                                <?php endforeach; ?>

                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($students as $student): ?>

                                <?php
                                $registrationId =
                                    (int) $student['registration_id'];
                                ?>

                                <tr>

                                    <td
                                        class="student-column"
                                        title="<?= h($student['full_name']) ?>"
                                    >
                                        <?= h($student['full_name']) ?>
                                    </td>

                                    <?php foreach ($attendanceDates as $date): ?>

                                        <?php

                                        $status =
                                            $attendanceMap[
                                                $registrationId
                                            ][
                                                $date
                                            ] ?? '—';

                                        $statusClass =
                                            attendanceStatusClass(
                                                $status
                                            );

                                        ?>

                                        <td class="status-cell">

                                            <?php if ($status === '—'): ?>

                                                <span class="status-empty">
                                                    —
                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="status-badge <?= h($statusClass) ?>"
                                                >
                                                    <?= h($status) ?>
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    <?php endforeach; ?>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>


                <!-- =================================================
                     MOBILE TABLE
                     ================================================= -->

                <div class="mobile-history">

                    <div class="mobile-attendance-table-wrapper">

                        <table class="mobile-attendance-table">

                            <thead>

                                <tr>

                                    <th class="student-column">
                                        Student Name
                                    </th>

                                    <?php foreach ($attendanceDates as $date): ?>

                                        <?php
                                        $ethiopianDate =
                                            formatEthiopianAttendanceDate($date);
                                        ?>

                                        <th title="<?= h($date) ?>">
                                            <?= h($ethiopianDate) ?>
                                        </th>

                                    <?php endforeach; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($students as $student): ?>

                                    <?php
                                    $registrationId =
                                        (int) $student['registration_id'];
                                    ?>

                                    <tr>

                                        <td
                                            class="student-column"
                                            title="<?= h($student['full_name']) ?>"
                                        >
                                            <?= h($student['full_name']) ?>
                                        </td>

                                        <?php foreach ($attendanceDates as $date): ?>

                                            <?php

                                            $status =
                                                $attendanceMap[
                                                    $registrationId
                                                ][
                                                    $date
                                                ] ?? '—';

                                            $statusClass =
                                                attendanceStatusClass(
                                                    $status
                                                );

                                            ?>

                                            <td class="status-cell">

                                                <?php if ($status === '—'): ?>

                                                    <span class="status-empty">
                                                        —
                                                    </span>

                                                <?php else: ?>

                                                    <span
                                                        class="status-badge <?= h($statusClass) ?>"
                                                    >
                                                        <?= h($status) ?>
                                                    </span>

                                                <?php endif; ?>

                                            </td>

                                        <?php endforeach; ?>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>


            <?php elseif (
                $classIsValid &&
                empty($attendanceDates)
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-calendar-x"></i>

                    <div class="empty-state-title">
                        No Attendance Records
                    </div>

                    <div class="empty-state-text">
                        There are no attendance records available for this class yet.
                    </div>

                </div>


            <?php elseif (
                $classIsValid &&
                empty($students)
            ): ?>

                <div class="empty-state">

                    <i class="bi bi-people"></i>

                    <div class="empty-state-title">
                        No Students Found
                    </div>

                    <div class="empty-state-text">
                        No students are currently registered in this class.
                    </div>

                </div>


            <?php elseif (empty($homeroomClasses)): ?>

                <div class="empty-state">

                    <i class="bi bi-person-workspace"></i>

                    <div class="empty-state-title">
                        No Homeroom Assignment
                    </div>

                    <div class="empty-state-text">
                        You do not currently have an active homeroom class assignment.
                    </div>

                </div>

            <?php endif; ?>

        </main>

    </div>


    <!-- =========================================================
         MOBILE BOTTOM NAVIGATION
         ========================================================= -->

    <nav class="mobile-bottom-nav">

        <a
            href="../homework.php"
            class="mobile-bottom-link"
        >
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a
            href="../result.php"
            class="mobile-bottom-link"
        >
            <i class="bi bi-bar-chart"></i>
            <span>Result</span>
        </a>

        <a
            href="../announcements.php"
            class="mobile-bottom-link"
        >
            <i class="bi bi-megaphone"></i>
            <span>Announcement</span>
        </a>

        <a
            href="../profile.php"
            class="mobile-bottom-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../../auth/logout.php"
            class="mobile-bottom-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>


    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
    ></script>

</body>

</html>