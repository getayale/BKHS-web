<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'teacher'
) {
    header('Location: ../auth/login.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

$statuses = [
    'Present',
    'Absent',
    'Late',
    'Excused'
];

$successMessage = '';
$errorMessage = '';
$warningMessage = '';

$page = max(1, (int) ($_GET['page'] ?? 1));

$perPage = 10;

/*
|--------------------------------------------------------------------------
| Helper functions
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

function redirectWithMessage(
    string $type,
    string $message,
    array $params = []
): void {
    $params['message_type'] = $type;
    $params['message'] = $message;

    header(
        'Location: daily-attendance.php?' .
        http_build_query($params)
    );

    exit;
}

function getEthiopianDateParts(): array
{
    $today = EthiopianCalendar::today();

    return [
        'year' => (int) ($today['year'] ?? 0),
        'month' => (int) ($today['month'] ?? 0),
        'day' => (int) ($today['day'] ?? 0),
    ];
}

function gregorianToEthiopianDate(string $date): array
{
    $parts = explode('-', $date);

    if (count($parts) !== 3) {
        throw new Exception(
            'Invalid Gregorian date.'
        );
    }

    $year = (int) $parts[0];
    $month = (int) $parts[1];
    $day = (int) $parts[2];

    return EthiopianCalendar::gregorianToEthiopian(
        $year,
        $month,
        $day
    );
}

function normalizeDate(string $date): ?string
{
    $date = trim($date);

    if (
        !preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $date
        )
    ) {
        return null;
    }

    $parts = explode('-', $date);

    if (
        !checkdate(
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[0]
        )
    ) {
        return null;
    }

    return $date;
}

/*
|--------------------------------------------------------------------------
| Messages from redirects
|--------------------------------------------------------------------------
*/

if (
    isset(
        $_GET['message_type'],
        $_GET['message']
    )
) {

    if (
        $_GET['message_type'] ===
        'success'
    ) {

        $successMessage =
            (string) $_GET['message'];

    } elseif (
        $_GET['message_type'] ===
        'warning'
    ) {

        $warningMessage =
            (string) $_GET['message'];

    } else {

        $errorMessage =
            (string) $_GET['message'];
    }
}

/*
|--------------------------------------------------------------------------
| Teacher profile
|--------------------------------------------------------------------------
*/

$teacher = null;

$teacherStmt = $conn->prepare("
    SELECT
        u.id AS user_id,
        u.full_name,
        u.email,
        u.phone,
        t.id AS teacher_id,
        t.photo_path
    FROM users u
    LEFT JOIN teachers t
        ON t.user_id = u.id
    WHERE u.id = ?
      AND LOWER(u.role) = 'teacher'
      AND u.is_deleted = 0
    LIMIT 1
");

if ($teacherStmt) {

    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacherResult =
        $teacherStmt->get_result();

    $teacher =
        $teacherResult->fetch_assoc();

    $teacherStmt->close();
}

if (!$teacher) {

    session_destroy();

    header(
        'Location: ../auth/login.php'
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Teacher photo
|--------------------------------------------------------------------------
*/

$teacherPhoto =
    '../assets/images/default-avatar.png';

if (!empty($teacher['photo_path'])) {

    $teacherPhoto =
        '../' .
        ltrim(
            $teacher['photo_path'],
            '/'
        );
}

/*
|--------------------------------------------------------------------------
| Active academic year
|--------------------------------------------------------------------------
*/

$academicYear = null;

$academicYearStmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE status = 'Active'
    ORDER BY id DESC
    LIMIT 1
");

if ($academicYearStmt) {

    $academicYearStmt->execute();

    $academicYearResult =
        $academicYearStmt->get_result();

    $academicYear =
        $academicYearResult->fetch_assoc();

    $academicYearStmt->close();
}

$academicYearId =
    $academicYear
        ? (int) $academicYear['id']
        : 0;

$academicYearName =
    $academicYear
        ? (string) $academicYear['name']
        : '';

/*
|--------------------------------------------------------------------------
| Current date
|--------------------------------------------------------------------------
*/

$todayGregorian = date('Y-m-d');

try {

    $todayEth =
        getEthiopianDateParts();

    $todayEthYear =
        $todayEth['year'];

    $todayEthMonth =
        $todayEth['month'];

    $todayEthDay =
        $todayEth['day'];

    $todayEthFormatted =
        EthiopianCalendar::todayFormatted();

} catch (Throwable $e) {

    $todayEthYear = 0;
    $todayEthMonth = 0;
    $todayEthDay = 0;

    $todayEthFormatted =
        'Ethiopian date unavailable';
}

/*
|--------------------------------------------------------------------------
| Teacher's homeroom classes
|--------------------------------------------------------------------------
*/

$homeroomClasses = [];

if ($academicYearName !== '') {

    $classStmt = $conn->prepare("
        SELECT
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
        ORDER BY hta.grade, hta.section
    ");

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
            $row =
            $classResult->fetch_assoc()
        ) {

            $homeroomClasses[] = $row;
        }

        $classStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Selected class
|--------------------------------------------------------------------------
*/

$selectedClassKey =
    trim(
        (string) ($_GET['class'] ?? '')
    );

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $selectedClassKey =
        trim(
            (string) ($_POST['class'] ?? '')
        );
}

$selectedClass = null;

foreach (
    $homeroomClasses as $class
) {

    $classKey =
        $class['grade'] .
        '-' .
        $class['section'];

    if (
        $classKey ===
        $selectedClassKey
    ) {

        $selectedClass = $class;

        break;
    }
}

/*
|--------------------------------------------------------------------------
| Default class
|--------------------------------------------------------------------------
*/

if (
    !$selectedClass &&
    count($homeroomClasses) > 0
) {

    $selectedClass =
        $homeroomClasses[0];

    $selectedClassKey =
        $selectedClass['grade'] .
        '-' .
        $selectedClass['section'];
}

$selectedGrade =
    $selectedClass
        ? (int) $selectedClass['grade']
        : 0;

$selectedSectionCode =
    $selectedClass
        ? (string) $selectedClass['section']
        : '';

$selectedGradeId = 0;
$selectedSectionId = 0;

/*
|--------------------------------------------------------------------------
| Resolve grade and section IDs
|--------------------------------------------------------------------------
*/

if (
    $selectedGrade > 0 &&
    $selectedSectionCode !== ''
) {

    $placementStmt = $conn->prepare("
        SELECT
            g.id AS grade_id,
            sec.id AS section_id
        FROM grades g
        INNER JOIN sections sec
            ON sec.code = ?
        WHERE g.grade_number = ?
        LIMIT 1
    ");

    if ($placementStmt) {

        $placementStmt->bind_param(
            'si',
            $selectedSectionCode,
            $selectedGrade
        );

        $placementStmt->execute();

        $placementResult =
            $placementStmt->get_result();

        $placement =
            $placementResult->fetch_assoc();

        if ($placement) {

            $selectedGradeId =
                (int) $placement['grade_id'];

            $selectedSectionId =
                (int) $placement['section_id'];
        }

        $placementStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Verify selected class belongs to teacher
|--------------------------------------------------------------------------
*/

function teacherOwnsClass(
    mysqli $conn,
    int $teacherUserId,
    string $academicYearName,
    int $grade,
    string $section
): bool {

    $stmt = $conn->prepare("
        SELECT id
        FROM homeroom_teacher_assignments
        WHERE teacher_user_id = ?
          AND academic_year = ?
          AND grade = ?
          AND section = ?
          AND is_active = 1
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'isis',
        $teacherUserId,
        $academicYearName,
        $grade,
        $section
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $exists =
        $result->num_rows > 0;

    $stmt->close();

    return $exists;
}

/*
|--------------------------------------------------------------------------
| Save today's attendance
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') ===
    'save_attendance'
) {

    if (!$academicYear) {

        redirectWithMessage(
            'error',
            'There is no active academic year.'
        );
    }

    if (
        !$selectedClass ||
        $selectedGrade <= 0 ||
        $selectedSectionCode === ''
    ) {

        redirectWithMessage(
            'error',
            'Please select a valid homeroom class.'
        );
    }

    if (
        !teacherOwnsClass(
            $conn,
            $teacherUserId,
            $academicYearName,
            $selectedGrade,
            $selectedSectionCode
        )
    ) {

        redirectWithMessage(
            'error',
            'You are not authorized to manage attendance for this class.'
        );
    }

    $attendanceDate =
        normalizeDate(
            $todayGregorian
        );

    if (!$attendanceDate) {

        redirectWithMessage(
            'error',
            'The current attendance date is invalid.'
        );
    }

    try {

        $eth =
            gregorianToEthiopianDate(
                $attendanceDate
            );

        $ethYear =
            (int) ($eth['year'] ?? 0);

        $ethMonth =
            (int) ($eth['month'] ?? 0);

        $ethDay =
            (int) ($eth['day'] ?? 0);

        $ethDateFormatted =
            EthiopianCalendar::format(
                $ethYear,
                $ethMonth,
                $ethDay
            );

        $conn->begin_transaction();

        /*
         * Get all students currently
         * registered in this class.
         */
        $studentsStmt = $conn->prepare("
            SELECT
                sr.id AS registration_id,
                sr.student_id,
                sr.academic_year_id,
                sr.grade_id,
                sr.section_id
            FROM student_registrations sr
            WHERE sr.academic_year_id = ?
              AND sr.grade_id = ?
              AND sr.section_id = ?
            ORDER BY sr.student_id
        ");

        if (!$studentsStmt) {

            throw new Exception(
                'Unable to load class students.'
            );
        }

        $studentsStmt->bind_param(
            'iii',
            $academicYearId,
            $selectedGradeId,
            $selectedSectionId
        );

        $studentsStmt->execute();

        $studentsResult =
            $studentsStmt->get_result();

        /*
         * Prepare attendance insert/update.
         */
        $attendanceStmt = $conn->prepare("
            INSERT INTO student_attendance (
                student_id,
                registration_id,
                academic_year_id,
                grade_id,
                section_id,
                attendance_date,
                ethiopian_year,
                ethiopian_month,
                ethiopian_day,
                ethiopian_date,
                status,
                marked_by,
                updated_by
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NULL
            )
            ON DUPLICATE KEY UPDATE
                registration_id = VALUES(registration_id),
                academic_year_id = VALUES(academic_year_id),
                grade_id = VALUES(grade_id),
                section_id = VALUES(section_id),
                ethiopian_year = VALUES(ethiopian_year),
                ethiopian_month = VALUES(ethiopian_month),
                ethiopian_day = VALUES(ethiopian_day),
                ethiopian_date = VALUES(ethiopian_date),
                status = VALUES(status),
                updated_by = VALUES(marked_by)
        ");

        if (!$attendanceStmt) {

            throw new Exception(
                'Unable to prepare attendance saving.'
            );
        }

        $savedCount = 0;

        while (
            $student =
            $studentsResult->fetch_assoc()
        ) {

            $studentId =
                (int) $student['student_id'];

            $registrationId =
                (int) $student['registration_id'];

            $status =
                $_POST['status'][$studentId]
                ?? 'Present';

            if (
                !in_array(
                    $status,
                    $statuses,
                    true
                )
            ) {

                $status = 'Present';
            }

            $attendanceStmt->bind_param(
                'iiiiisiiissi',
                $studentId,
                $registrationId,
                $academicYearId,
                $selectedGradeId,
                $selectedSectionId,
                $attendanceDate,
                $ethYear,
                $ethMonth,
                $ethDay,
                $ethDateFormatted,
                $status,
                $teacherUserId
            );

            if (
                !$attendanceStmt->execute()
            ) {

                throw new Exception(
                    'Failed to save attendance.'
                );
            }

            $savedCount++;
        }

        $attendanceStmt->close();
        $studentsStmt->close();

        $conn->commit();

        redirectWithMessage(
            'success',
            "Attendance saved successfully for {$savedCount} students.",
            [
                'class' =>
                    $selectedClassKey
            ]
        );

    } catch (Throwable $e) {

        if ($conn->in_transaction) {
            $conn->rollback();
        }

        redirectWithMessage(
            'error',
            'Attendance could not be saved: ' .
            $e->getMessage(),
            [
                'class' =>
                    $selectedClassKey
            ]
        );
    }
}

/*
|--------------------------------------------------------------------------
| Students for today's attendance
|--------------------------------------------------------------------------
*/

$totalStudents = 0;
$totalPages = 1;
$todayStudents = [];

if (
    $academicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0
) {

    $countStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM student_registrations sr
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
    ");

    if ($countStmt) {

        $countStmt->bind_param(
            'iii',
            $academicYearId,
            $selectedGradeId,
            $selectedSectionId
        );

        $countStmt->execute();

        $countResult =
            $countStmt->get_result();

        $countRow =
            $countResult->fetch_assoc();

        $totalStudents =
            (int) (
                $countRow['total'] ?? 0
            );

        $countStmt->close();
    }

    $totalPages =
        max(
            1,
            (int) ceil(
                $totalStudents /
                $perPage
            )
        );

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset =
        ($page - 1) *
        $perPage;

    $studentsStmt = $conn->prepare("
        SELECT
            sr.id AS registration_id,
            s.id AS student_id,
            s.student_code,
            s.full_name,

            COALESCE(
                sa.status,
                'Present'
            ) AS attendance_status

        FROM student_registrations sr

        INNER JOIN students s
            ON s.id = sr.student_id

        LEFT JOIN student_attendance sa
            ON sa.student_id = sr.student_id
            AND sa.attendance_date = ?
            AND sa.academic_year_id = sr.academic_year_id
            AND sa.grade_id = sr.grade_id
            AND sa.section_id = sr.section_id

        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?

        ORDER BY s.student_code

        LIMIT ? OFFSET ?
    ");

    if ($studentsStmt) {

        $studentsStmt->bind_param(
            'siiiii',
            $todayGregorian,
            $academicYearId,
            $selectedGradeId,
            $selectedSectionId,
            $perPage,
            $offset
        );

        $studentsStmt->execute();

        $studentsResult =
            $studentsStmt->get_result();

        while (
            $row =
            $studentsResult->fetch_assoc()
        ) {

            $todayStudents[] =
                $row;
        }

        $studentsStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Today's statistics
|--------------------------------------------------------------------------
*/

$todayStats = [
    'Present' => 0,
    'Absent' => 0,
    'Late' => 0,
    'Excused' => 0
];

if (
    $academicYearId > 0 &&
    $selectedGradeId > 0 &&
    $selectedSectionId > 0
) {

    $statsStmt = $conn->prepare("
        SELECT
            sa.status,
            COUNT(*) AS total
        FROM student_attendance sa
        WHERE sa.attendance_date = ?
          AND sa.academic_year_id = ?
          AND sa.grade_id = ?
          AND sa.section_id = ?
        GROUP BY sa.status
    ");

    if ($statsStmt) {

        $statsStmt->bind_param(
            'siii',
            $todayGregorian,
            $academicYearId,
            $selectedGradeId,
            $selectedSectionId
        );

        $statsStmt->execute();

        $statsResult =
            $statsStmt->get_result();

        while (
            $row =
            $statsResult->fetch_assoc()
        ) {

            if (
                isset(
                    $todayStats[
                        $row['status']
                    ]
                )
            ) {

                $todayStats[
                    $row['status']
                ] =
                    (int) $row['total'];
            }
        }

        $statsStmt->close();
    }
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
        content="Daily attendance management for BKHS teachers."
    >

    <title>Daily Attendance | BKHS Teacher</title>

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

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            background: #f5f7fb;
            color: #111827;
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* =========================================================
           SIDEBAR
        ========================================================= */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: #111827;
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 74px;
            display: flex;
            align-items: center;
            padding: 0 22px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 11px;
            font-size: 19px;
            flex-shrink: 0;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 10px;
            color: #9ca3af;
            margin-top: 3px;
        }

        .sidebar-section {
            padding: 22px 14px 8px;
            color: #6b7280;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-nav {
            padding: 5px 12px 20px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 12px;
            height: 44px;
            padding: 0 13px;
            margin-bottom: 4px;
            color: #cbd5e1;
            text-decoration: none;
            border-radius: 9px;
            font-size: 13px;
            font-weight: 500;
            transition:
                background .2s ease,
                color .2s ease,
                transform .2s ease;
        }

        .sidebar-nav a i {
            font-size: 17px;
            width: 20px;
            flex-shrink: 0;
        }

        .sidebar-nav a:hover {
            background: rgba(255,255,255,.07);
            color: #fff;
            transform: translateX(2px);
        }

        .sidebar-nav a.active {
            background: #2563eb;
            color: #fff;
        }

        .sidebar-profile {
            position: sticky;
            bottom: 0;
            margin: 12px;
            padding: 12px;
            border-radius: 12px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.06);
            backdrop-filter: blur(8px);
        }

        .profile-photo {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            object-fit: cover;
            background: #374151;
            flex-shrink: 0;
        }

        /* =========================================================
           MAIN / TOPBAR
        ========================================================= */

        .main {
            margin-left: 260px;
            min-height: 100vh;
        }

        .topbar {
            height: 74px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.2;
        }

        .topbar-date {
            font-size: 12px;
            color: #6b7280;
            margin-top: 3px;
        }

        .menu-btn {
            display: none;
            width: 40px;
            height: 40px;
            border: 1px solid #e5e7eb;
            border-radius: 9px;
            background: #fff;
            color: #374151;
            font-size: 21px;
            align-items: center;
            justify-content: center;
        }

        .menu-btn:hover {
            background: #f8fafc;
            color: #2563eb;
        }

        .content {
            padding: 30px;
            max-width: 1550px;
            margin: auto;
        }

        /* =========================================================
           PAGE HEADING
        ========================================================= */

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 18px;
        }

        .page-heading h1 {
            font-size: 24px;
            font-weight: 800;
            margin: 0;
            letter-spacing: -.02em;
        }

        .page-heading p {
            margin: 7px 0 0;
            color: #6b7280;
            font-size: 13px;
        }

        .date-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 15px 18px;
            text-align: right;
            min-width: 225px;
            box-shadow: 0 2px 8px rgba(15,23,42,.025);
        }

        .date-card .label {
            color: #6b7280;
            font-size: 10px;
            font-weight: 600;
            margin-bottom: 5px;
            letter-spacing: .03em;
        }

        .date-card .ethiopian-date {
            font-weight: 700;
            font-size: 14px;
        }

        .date-card .gregorian-date {
            color: #6b7280;
            font-size: 11px;
            margin-top: 4px;
        }

        /* =========================================================
           ATTENDANCE PAGE NAVIGATION
        ========================================================= */

        .attendance-navigation {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 6px;
            display: inline-flex;
            gap: 4px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(15,23,42,.035);
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
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition:
                background .18s ease,
                color .18s ease,
                box-shadow .18s ease;
        }

        .attendance-nav-link i {
            font-size: 14px;
        }

        .attendance-nav-link:hover {
            color: #2563eb;
            background: #eff6ff;
        }

        .attendance-nav-link.active {
            background: #2563eb;
            color: #fff;
            box-shadow: 0 4px 10px rgba(37,99,235,.18);
        }

        .attendance-nav-link.active:hover {
            background: #2563eb;
            color: #fff;
        }

        /* =========================================================
           MOBILE DASHBOARD BUTTON
        ========================================================= */

        .mobile-dashboard-btn {
            display: none;
        }

        /* =========================================================
           CARDS
        ========================================================= */

        .card-box {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
            overflow: hidden;
        }

        .card-header-custom {
            padding: 18px 20px;
            border-bottom: 1px solid #edf0f4;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            margin: 0;
        }

        .card-subtitle {
            font-size: 11px;
            color: #6b7280;
            margin-top: 4px;
        }

        .card-body-custom {
            padding: 20px;
        }

        /* =========================================================
           FORMS
        ========================================================= */

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 7px;
        }

        .form-select,
        .form-control {
            height: 44px;
            border-radius: 8px;
            border-color: #dfe3e8;
            font-size: 13px;
        }

        .form-select:focus,
        .form-control:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37,99,235,.1);
        }

        .btn {
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            min-height: 40px;
        }

        .btn-primary {
            background: #2563eb;
            border-color: #2563eb;
        }

        .btn-primary:hover {
            background: #1d4ed8;
            border-color: #1d4ed8;
        }

        .btn-outline-primary {
            color: #2563eb;
            border-color: #bfdbfe;
            background: #fff;
        }

        .btn-outline-primary:hover {
            color: #fff;
            background: #2563eb;
            border-color: #2563eb;
        }

        /* =========================================================
           STATISTICS
        ========================================================= */

        .stat-card {
            padding: 18px;
            height: 100%;
            transition:
                transform .18s ease,
                box-shadow .18s ease;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(15,23,42,.06);
        }

        .stat-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eff6ff;
            color: #2563eb;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            line-height: 1;
        }

        .stat-label {
            color: #6b7280;
            font-size: 11px;
            margin-top: 6px;
        }

        /* =========================================================
           TABLE
        ========================================================= */

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .attendance-table {
            min-width: 700px;
            margin: 0;
        }

        .attendance-table th {
            background: #f8fafc;
            color: #64748b;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .03em;
            padding: 13px 14px;
            white-space: nowrap;
            border-bottom: 1px solid #e5e7eb;
        }

        .attendance-table td {
            padding: 11px 14px;
            vertical-align: middle;
            border-bottom: 1px solid #eef1f5;
            font-size: 12px;
        }

        .attendance-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .attendance-table tbody tr:hover {
            background: #fafcff;
        }

        .student-code {
            font-weight: 700;
            color: #2563eb;
        }

        .student-name {
            font-weight: 600;
        }

        .status-select {
            min-width: 125px;
            height: 36px;
            font-size: 11px;
        }

        /* =========================================================
           BADGES / ALERTS
        ========================================================= */

        .class-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 5px 9px;
            border-radius: 6px;
            background: #eff6ff;
            color: #2563eb;
            font-size: 10px;
            font-weight: 700;
        }

        .alert {
            border-radius: 10px;
            font-size: 12px;
            border-width: 1px;
        }

        /* =========================================================
           PAGINATION
        ========================================================= */

        .pagination {
            margin: 0;
        }

        .pagination .page-link {
            font-size: 11px;
            color: #374151;
            border-color: #e5e7eb;
            min-width: 34px;
            min-height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .pagination .page-item.active .page-link {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        /* =========================================================
           EMPTY STATE
        ========================================================= */

        .empty-state {
            padding: 55px 20px;
            text-align: center;
            color: #6b7280;
        }

        .empty-state i {
            font-size: 42px;
            color: #cbd5e1;
            display: block;
            margin-bottom: 14px;
        }

        .empty-state h5 {
            color: #374151;
            font-size: 15px;
            font-weight: 700;
        }

        .empty-state p {
            max-width: 520px;
            margin-left: auto;
            margin-right: auto;
            font-size: 12px;
        }

        /* =========================================================
           MOBILE BOTTOM NAV
        ========================================================= */

        .mobile-bottom-nav {
            display: none;
        }

        /* =========================================================
           OVERLAY
        ========================================================= */

        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.45);
            z-index: 1040;
        }

        /* =========================================================
           TABLET / MOBILE
        ========================================================= */

        @media (max-width: 991px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .overlay.show {
                display: block;
            }

            .main {
                margin-left: 0;
            }

            .menu-btn {
                display: inline-flex;
            }

            .topbar {
                height: 68px;
                padding: 0 16px;
            }

            .topbar-title {
                font-size: 16px;
            }

            .content {
                padding: 20px 16px 100px;
            }

            .page-heading {
                flex-direction: column;
                gap: 14px;
                margin-bottom: 16px;
            }

            .page-heading h1 {
                font-size: 22px;
            }

            .date-card {
                width: 100%;
                text-align: left;
                min-width: 0;
            }

            /*
             * Three attendance navigation controls
             * on small screens.
             */
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
                white-space: nowrap;
            }

            .attendance-nav-link i {
                font-size: 13px;
            }

            .mobile-dashboard-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                min-height: 36px;
                padding: 0 11px;
                border: 1px solid #dbe3ef;
                border-radius: 8px;
                background: #fff;
                color: #2563eb;
                text-decoration: none;
                font-size: 11px;
                font-weight: 600;
            }

            .mobile-dashboard-btn:hover {
                background: #eff6ff;
                color: #1d4ed8;
            }

            .mobile-bottom-nav {
                position: fixed;
                left: 0;
                right: 0;
                bottom: 0;
                height: 68px;
                background: rgba(255,255,255,.97);
                border-top: 1px solid #e5e7eb;
                z-index: 1035;
                display: grid;
                grid-template-columns:
                    repeat(5, 1fr);
                padding-bottom:
                    env(safe-area-inset-bottom);
                box-shadow:
                    0 -5px 18px rgba(15,23,42,.07);
                backdrop-filter: blur(12px);
            }

            .mobile-nav-item {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 4px;
                min-width: 0;
                color: #64748b;
                text-decoration: none;
                font-size: 9px;
                font-weight: 600;
                transition: color .18s ease;
            }

            .mobile-nav-item i {
                font-size: 18px;
                line-height: 1;
            }

            .mobile-nav-item:hover {
                color: #2563eb;
            }

            .mobile-nav-item.logout {
                color: #dc2626;
            }

            .mobile-nav-item.logout:hover {
                color: #b91c1c;
            }

            .card-header-custom {
                padding: 16px;
            }

            .card-body-custom {
                padding: 16px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-value {
                font-size: 21px;
            }
        }

        /* =========================================================
           SMALL MOBILE
        ========================================================= */

        @media (max-width: 575px) {

            .topbar {
                padding: 0 12px;
            }

            .topbar-date {
                display: none;
            }

            .topbar-title {
                font-size: 15px;
            }

            .mobile-dashboard-btn span {
                display: inline;
            }

            .content {
                padding:
                    16px
                    12px
                    100px;
            }

            .page-heading h1 {
                font-size: 20px;
            }

            .page-heading p {
                font-size: 12px;
            }

            .date-card {
                padding: 13px 15px;
            }

            /*
             * Keep all three attendance buttons visible
             * and compact on very small screens.
             */
            .attendance-navigation {
                padding: 5px;
                gap: 3px;
            }

            .attendance-nav-link {
                min-height: 38px;
                padding: 0 3px;
                gap: 4px;
                font-size: 9px;
            }

            .attendance-nav-link i {
                font-size: 12px;
            }

            .card-header-custom {
                align-items: flex-start;
                flex-direction: column;
            }

            .card-header-custom .btn {
                width: 100%;
            }

            .card-body-custom {
                padding: 14px;
            }

            .stat-card {
                padding: 14px;
            }

            .stat-icon {
                width: 34px;
                height: 34px;
                margin-bottom: 10px;
            }

            .stat-value {
                font-size: 20px;
            }

            .attendance-table {
                min-width: 600px;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 10px 11px;
            }

            .status-select {
                min-width: 115px;
            }

            .mobile-nav-item {
                font-size: 8px;
            }

            .mobile-nav-item i {
                font-size: 17px;
            }
        }

    </style>

</head>

<body>

<div
    class="overlay"
    id="sidebarOverlay"
></div>

<!-- ============================================================
     DESKTOP SIDEBAR
============================================================ -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <div class="brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <div>

            <div class="brand-title">
                BKHS Teacher
            </div>

            <div class="brand-subtitle">
                School Management
            </div>

        </div>

    </div>

    <div class="sidebar-section">
        Main
    </div>

    <nav class="sidebar-nav">

        <a href="dashboard.php">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a href="subjects.php">
            <i class="bi bi-book-fill"></i>
            <span>Subjects</span>
        </a>

        <a href="classes.php">
            <i class="bi bi-people-fill"></i>
            <span>Classes</span>
        </a>

        <a href="result.php">
            <i class="bi bi-bar-chart-fill"></i>
            <span>Result</span>
        </a>

        <a
            href="daily-attendance.php"
            class="active"
        >
            <i class="bi bi-calendar-check-fill"></i>
            <span>Daily Attendance</span>
        </a>

       

        <a href="homework.php">
            <i class="bi bi-journal-text"></i>
            <span>Homework</span>
        </a>

        <a href="announcements.php">
            <i class="bi bi-megaphone-fill"></i>
            <span>Announcement</span>
        </a>

      

        <a href="profile.php">
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a href="../auth/logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

    <div class="sidebar-profile">

        <div class="d-flex align-items-center gap-2">

            <img
                src="<?= h($teacherPhoto) ?>"
                class="profile-photo"
                alt="Teacher"
            >

            <div class="min-w-0">

                <div
                    class="text-white text-truncate"
                    style="
                        font-size:12px;
                        font-weight:700;
                    "
                >
                    <?= h(
                        (string) $teacher['full_name']
                    ) ?>
                </div>

                <div
                    style="
                        font-size:10px;
                        color:#9ca3af;
                    "
                >
                    Teacher
                </div>

            </div>

        </div>

    </div>

</aside>

<!-- ============================================================
     MAIN
============================================================ -->

<main class="main">

    <header class="topbar">

        <div
            class="d-flex align-items-center gap-3"
        >

          

            <div>

            </div>

        </div>

        <div
            class="d-flex align-items-center gap-2"
        >

            <a
                href="dashboard.php"
                class="mobile-dashboard-btn"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                <span>Dashboard</span>
            </a>

            <span class="class-badge">

                <i
                    class="bi bi-calendar3 me-1"
                ></i>

                <?= h(
                    $academicYearName
                    ?: 'No Active Year'
                ) ?>

            </span>

        </div>

    </header>

    <div class="content">

        <!-- ====================================================
             PAGE HEADING
        ===================================================== -->

        <div class="page-heading">

            <div>

                <h1>
                    Daily Attendance
                </h1>

                <p>
                    Mark today's attendance
                    for your assigned homeroom class.
                </p>

            </div>

            <div class="date-card">

                <div class="label">
                    TODAY — ETHIOPIAN CALENDAR
                </div>

                <div class="ethiopian-date">
                    <?= h(
                        $todayEthFormatted
                    ) ?>
                </div>

                <div class="gregorian-date">
                    <?= h(
                        date(
                            'F j, Y',
                            strtotime(
                                $todayGregorian
                            )
                        )
                    ) ?>
                </div>

            </div>

        </div>

        <!-- ====================================================
             ATTENDANCE NAVIGATION
        ===================================================== -->

        <div class="attendance-navigation">

            <!-- CREATE / TAKING ATTENDANCE -->

            <a
                href="daily-attendance.php"
                class="attendance-nav-link active"
            >
                <i class="bi bi-calendar-check"></i>
                <span>Taking Attendance</span>
            </a>

            <!-- ATTENDANCE HISTORY -->

            <a
                href="daily-attendance/history.php"
                class="attendance-nav-link"
            >
                <i class="bi bi-clock-history"></i>
                <span>Attendance History</span>
            </a>

            <!-- EDIT ATTENDANCE -->

            <a
                href="daily-attendance/edit.php"
                class="attendance-nav-link"
            >
                <i class="bi bi-pencil-square"></i>
                <span>Edit Attendance</span>
            </a>

        </div>

        <!-- ====================================================
             MESSAGES
        ===================================================== -->

        <?php if ($successMessage): ?>

            <div
                class="alert alert-success d-flex align-items-start"
            >

                <i
                    class="bi bi-check-circle-fill me-2 mt-1"
                ></i>

                <div>
                    <?= h($successMessage) ?>
                </div>

            </div>

        <?php endif; ?>

        <?php if ($errorMessage): ?>

            <div
                class="alert alert-danger d-flex align-items-start"
            >

                <i
                    class="bi bi-exclamation-circle-fill me-2 mt-1"
                ></i>

                <div>
                    <?= h($errorMessage) ?>
                </div>

            </div>

        <?php endif; ?>

        <?php if ($warningMessage): ?>

            <div
                class="alert alert-warning d-flex align-items-start"
            >

                <i
                    class="bi bi-exclamation-triangle-fill me-2 mt-1"
                ></i>

                <div>
                    <?= h($warningMessage) ?>
                </div>

            </div>

        <?php endif; ?>

        <?php if (!$academicYear): ?>

            <div class="alert alert-warning">

                <i
                    class="bi bi-calendar-x me-2"
                ></i>

                There is currently no active
                academic year.

            </div>

        <?php endif; ?>

        <!-- ====================================================
             NO HOMEROOM CLASS
        ===================================================== -->

        <?php if (
            count($homeroomClasses) === 0
        ): ?>

            <div class="card-box">

                <div class="empty-state">

                    <i
                        class="bi bi-people"
                    ></i>

                    <h5 class="mb-2">
                        No Homeroom Class Assigned
                    </h5>

                    <p class="mb-0">
                        You currently do not have a
                        homeroom class assigned for
                        the active academic year.
                    </p>

                </div>

            </div>

        <?php else: ?>

            <!-- =================================================
                 CLASS SELECTOR
            ================================================== -->

            <div class="card-box mb-4">

                <div class="card-body-custom">

                    <form
                        method="get"
                        class="row g-3 align-items-end"
                    >

                        <div class="col-lg-5">

                            <label class="form-label">
                                Homeroom Class
                            </label>

                            <select
                                name="class"
                                class="form-select"
                                onchange="this.form.submit()"
                            >

                                <?php foreach (
                                    $homeroomClasses
                                    as $class
                                ): ?>

                                    <?php

                                    $classKey =
                                        $class['grade'] .
                                        '-' .
                                        $class['section'];

                                    ?>

                                    <option
                                        value="<?= h(
                                            $classKey
                                        ) ?>"
                                        <?= $classKey ===
                                            $selectedClassKey
                                            ? 'selected'
                                            : '' ?>
                                    >

                                        <?= h(
                                            $class['grade_name']
                                            ??
                                            'Grade ' .
                                            $class['grade']
                                        ) ?>

                                        -
                                        Section
                                        <?= h(
                                            $class['section']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-lg-4">

                            <label class="form-label">
                                Academic Year
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= h(
                                    $academicYearName
                                ) ?>"
                                readonly
                            >

                        </div>

                        <div class="col-lg-3">

                            <div
                                class="class-badge w-100"
                                style="height:44px;"
                            >

                                <i
                                    class="bi bi-people-fill me-1"
                                ></i>

                                <?= $totalStudents ?>

                                Students

                            </div>

                        </div>

                    </form>

                </div>

            </div>

            <!-- =================================================
                 STATISTICS
            ================================================== -->

            <div class="row g-3 mb-4">

                <div class="col-6 col-lg-3">

                    <div
                        class="card-box stat-card"
                    >

                        <div class="stat-icon">

                            <i
                                class="bi bi-check-circle-fill"
                            ></i>

                        </div>

                        <div class="stat-value">
                            <?= $todayStats['Present'] ?>
                        </div>

                        <div class="stat-label">
                            Present
                        </div>

                    </div>

                </div>

                <div class="col-6 col-lg-3">

                    <div
                        class="card-box stat-card"
                    >

                        <div
                            class="stat-icon"
                            style="
                                background:#fef2f2;
                                color:#dc2626;
                            "
                        >

                            <i
                                class="bi bi-x-circle-fill"
                            ></i>

                        </div>

                        <div class="stat-value">
                            <?= $todayStats['Absent'] ?>
                        </div>

                        <div class="stat-label">
                            Absent
                        </div>

                    </div>

                </div>

                <div class="col-6 col-lg-3">

                    <div
                        class="card-box stat-card"
                    >

                        <div
                            class="stat-icon"
                            style="
                                background:#fffbeb;
                                color:#b45309;
                            "
                        >

                            <i
                                class="bi bi-clock-fill"
                            ></i>

                        </div>

                        <div class="stat-value">
                            <?= $todayStats['Late'] ?>
                        </div>

                        <div class="stat-label">
                            Late
                        </div>

                    </div>

                </div>

                <div class="col-6 col-lg-3">

                    <div
                        class="card-box stat-card"
                    >

                        <div
                            class="stat-icon"
                            style="
                                background:#eff6ff;
                                color:#2563eb;
                            "
                        >

                            <i
                                class="bi bi-info-circle-fill"
                            ></i>

                        </div>

                        <div class="stat-value">
                            <?= $todayStats['Excused'] ?>
                        </div>

                        <div class="stat-label">
                            Excused
                        </div>

                    </div>

                </div>

            </div>

            <!-- =================================================
                 TODAY'S ATTENDANCE
            ================================================== -->

            <div class="card-box mb-4">

                <div class="card-header-custom">

                    <div>

                        <h2 class="card-title">
                            Today's Attendance
                        </h2>

                        <div class="card-subtitle">

                            <?= h(
                                $todayEthFormatted
                            ) ?>

                            ·

                            <?= h(
                                $academicYearName
                            ) ?>

                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn btn-outline-primary"
                        id="markAllPresent"
                    >

                        <i
                            class="bi bi-check2-all me-1"
                        ></i>

                        Mark All Present

                    </button>

                </div>

                <form method="post">

                    <input
                        type="hidden"
                        name="action"
                        value="save_attendance"
                    >

                    <input
                        type="hidden"
                        name="class"
                        value="<?= h(
                            $selectedClassKey
                        ) ?>"
                    >

                    <div class="table-wrap">

                        <table
                            class="table attendance-table mb-0"
                        >

                            <thead>

                                <tr>

                                    <th
                                        style="width:220px;"
                                    >
                                        Student Code
                                    </th>

                                    <th>
                                        Student Name
                                    </th>

                                    <th
                                        style="width:180px;"
                                    >
                                        Status
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                count($todayStudents) === 0
                            ): ?>

                                <tr>

                                    <td
                                        colspan="3"
                                        class="text-center py-5 text-muted"
                                    >

                                        <i
                                            class="bi bi-people d-block mb-2"
                                            style="
                                                font-size:28px;
                                                color:#cbd5e1;
                                            "
                                        ></i>

                                        No students found
                                        in this class.

                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach (
                                    $todayStudents
                                    as $student
                                ): ?>

                                    <tr>

                                        <td>

                                            <span
                                                class="student-code"
                                            >
                                                <?= h(
                                                    $student[
                                                        'student_code'
                                                    ]
                                                ) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <span
                                                class="student-name"
                                            >
                                                <?= h(
                                                    $student[
                                                        'full_name'
                                                    ]
                                                ) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <select
                                                name="status[<?= (int) $student['student_id'] ?>]"
                                                class="form-select status-select attendance-status"
                                            >

                                                <?php foreach (
                                                    $statuses
                                                    as $status
                                                ): ?>

                                                    <option
                                                        value="<?= h(
                                                            $status
                                                        ) ?>"
                                                        <?= $student[
                                                            'attendance_status'
                                                        ] ===
                                                            $status
                                                            ? 'selected'
                                                            : '' ?>
                                                    >
                                                        <?= h(
                                                            $status
                                                        ) ?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if (
                        count($todayStudents) > 0
                    ): ?>

                        <div
                            class="card-body-custom border-top"
                        >

                            <div
                                class="
                                    d-flex
                                    justify-content-between
                                    align-items-center
                                    flex-wrap
                                    gap-3
                                "
                            >

                                <div
                                    class="text-muted"
                                    style="
                                        font-size:11px;
                                    "
                                >

                                    Showing
                                    <?= count(
                                        $todayStudents
                                    ) ?>
                                    of
                                    <?= $totalStudents ?>
                                    students

                                </div>

                                <button
                                    type="submit"
                                    class="btn btn-primary px-4"
                                >

                                    <i
                                        class="bi bi-save2 me-1"
                                    ></i>

                                    Save Attendance

                                </button>

                            </div>

                        </div>

                    <?php endif; ?>

                </form>

                <!-- =================================================
                     STUDENT PAGINATION
                ================================================== -->

                <?php if (
                    $totalPages > 1
                ): ?>

                    <div
                        class="card-body-custom border-top"
                    >

                        <nav
                            aria-label="Student pagination"
                        >

                            <ul
                                class="
                                    pagination
                                    justify-content-end
                                    flex-wrap
                                    gap-1
                                "
                            >

                                <?php

                                $previousPage =
                                    max(
                                        1,
                                        $page - 1
                                    );

                                $nextPage =
                                    min(
                                        $totalPages,
                                        $page + 1
                                    );

                                ?>

                                <li
                                    class="
                                        page-item
                                        <?= $page <= 1
                                            ? 'disabled'
                                            : '' ?>
                                    "
                                >

                                    <a
                                        class="page-link"
                                        href="?<?= http_build_query([
                                            'class' =>
                                                $selectedClassKey,
                                            'page' =>
                                                $previousPage
                                        ]) ?>"
                                    >
                                        Previous
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
                                    $p = $startPage;
                                    $p <= $endPage;
                                    $p++
                                ): ?>

                                    <li
                                        class="
                                            page-item
                                            <?= $p === $page
                                                ? 'active'
                                                : '' ?>
                                        "
                                    >

                                        <a
                                            class="page-link"
                                            href="?<?= http_build_query([
                                                'class' =>
                                                    $selectedClassKey,
                                                'page' =>
                                                    $p
                                            ]) ?>"
                                        >
                                            <?= $p ?>
                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <li
                                    class="
                                        page-item
                                        <?= $page >=
                                            $totalPages
                                            ? 'disabled'
                                            : '' ?>
                                    "
                                >

                                    <a
                                        class="page-link"
                                        href="?<?= http_build_query([
                                            'class' =>
                                                $selectedClassKey,
                                            'page' =>
                                                $nextPage
                                        ]) ?>"
                                    >
                                        Next
                                    </a>

                                </li>

                            </ul>

                        </nav>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

<!-- ============================================================
     MOBILE BOTTOM NAVIGATION
============================================================ -->

<nav
    class="mobile-bottom-nav"
    aria-label="Teacher mobile navigation"
>

    <a
        href="homework.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-journal-text"></i>
        <span>Homework</span>
    </a>

    <a
        href="result.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Result</span>
    </a>

    <a
        href="announcements.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-megaphone"></i>
        <span>Announcement</span>
    </a>

    <a
        href="profile.php"
        class="mobile-nav-item"
    >
        <i class="bi bi-person-circle"></i>
        <span>Profile</span>
    </a>

    <a
        href="../auth/logout.php"
        class="mobile-nav-item logout"
    >
        <i class="bi bi-box-arrow-right"></i>
        <span>Logout</span>
    </a>

</nav>

<script>

    const menuBtn =
        document.getElementById('menuBtn');

    const sidebar =
        document.getElementById('sidebar');

    const overlay =
        document.getElementById(
            'sidebarOverlay'
        );

    if (menuBtn) {

        menuBtn.addEventListener(
            'click',
            () => {

                sidebar.classList.toggle(
                    'show'
                );

                overlay.classList.toggle(
                    'show'
                );

            }
        );
    }

    if (overlay) {

        overlay.addEventListener(
            'click',
            () => {

                sidebar.classList.remove(
                    'show'
                );

                overlay.classList.remove(
                    'show'
                );

            }
        );
    }

    /*
     * Mark all visible students as Present.
     */
    const markAllPresent =
        document.getElementById(
            'markAllPresent'
        );

    if (markAllPresent) {

        markAllPresent.addEventListener(
            'click',
            () => {

                document
                    .querySelectorAll(
                        '.attendance-status'
                    )
                    .forEach(
                        select => {

                            select.value =
                                'Present';

                        }
                    );

            }
        );
    }

</script>

</body>

</html>