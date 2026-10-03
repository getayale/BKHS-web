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

require_once '../../config/database.php';
require_once '../../includes/EthiopianCalendar.php';

$conn->set_charset('utf8mb4');

$teacherUserId = (int) $_SESSION['user_id'];

$successMessage = '';
$errorMessage = '';

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function editAttendanceUrl(int $classId, string $date = ''): string
{
    $params = ['class' => $classId];

    if ($date !== '') {
        $params['date'] = $date;
    }

    return 'edit.php?' . http_build_query($params);
}

/*
|--------------------------------------------------------------------------
| Teacher Profile
|--------------------------------------------------------------------------
*/

$teacher = null;

$stmt = $conn->prepare("
    SELECT
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
    LIMIT 1
");

$stmt->bind_param('i', $teacherUserId);
$stmt->execute();

$result = $stmt->get_result();
$teacher = $result->fetch_assoc();

$stmt->close();

if (!$teacher) {
    session_destroy();
    header('Location: ../../auth/login.php');
    exit;
}

$teacherPhoto = '../../public/image/logo.webp';

/*
|--------------------------------------------------------------------------
| Active Academic Year
|--------------------------------------------------------------------------
*/

$activeAcademicYear = null;

$stmt = $conn->prepare("
    SELECT
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
    LIMIT 1
");

$stmt->execute();

$result = $stmt->get_result();
$activeAcademicYear = $result->fetch_assoc();

$stmt->close();

/*
|--------------------------------------------------------------------------
| Today
|--------------------------------------------------------------------------
*/

$todayGregorian = (
    new DateTimeImmutable(
        'now',
        new DateTimeZone('Africa/Addis_Ababa')
    )
)->format('Y-m-d');

$todayEthiopian = EthiopianCalendar::fromGregorian(
    $todayGregorian
);

/*
|--------------------------------------------------------------------------
| Teacher Homeroom Assignments
|--------------------------------------------------------------------------
*/

$homeroomAssignments = [];

if ($activeAcademicYear) {
    $stmt = $conn->prepare("
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

    $academicYearName = (string) $activeAcademicYear['name'];

    $stmt->bind_param(
        'is',
        $teacherUserId,
        $academicYearName
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $homeroomAssignments[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Class
|--------------------------------------------------------------------------
*/

$selectedAssignmentId = isset($_GET['class'])
    ? (int) $_GET['class']
    : 0;

$selectedAssignment = null;

foreach ($homeroomAssignments as $assignment) {
    if ((int) $assignment['id'] === $selectedAssignmentId) {
        $selectedAssignment = $assignment;
        break;
    }
}

if (
    !$selectedAssignment &&
    !empty($homeroomAssignments)
) {
    $selectedAssignment = $homeroomAssignments[0];
    $selectedAssignmentId = (int) $selectedAssignment['id'];
}

/*
|--------------------------------------------------------------------------
| Resolve Grade / Section IDs
|--------------------------------------------------------------------------
*/

$gradeId = 0;
$sectionId = 0;

if ($selectedAssignment) {
    $gradeNumber = (int) $selectedAssignment['grade'];
    $sectionCode = (string) $selectedAssignment['section'];

    $stmt = $conn->prepare("
        SELECT
            g.id AS grade_id,
            sec.id AS section_id
        FROM grades g
        INNER JOIN sections sec
            ON sec.code = ?
        WHERE g.grade_number = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'si',
        $sectionCode,
        $gradeNumber
    );

    $stmt->execute();

    $result = $stmt->get_result();
    $gradeSection = $result->fetch_assoc();

    $stmt->close();

    if ($gradeSection) {
        $gradeId = (int) $gradeSection['grade_id'];
        $sectionId = (int) $gradeSection['section_id'];
    }
}

/*
|--------------------------------------------------------------------------
| Selected Date
|--------------------------------------------------------------------------
|
| The database stores attendance_date as Gregorian.
| The UI displays the equivalent Ethiopian date.
|
*/

$selectedDate = isset($_GET['date'])
    ? trim((string) $_GET['date'])
    : '';

/*
|--------------------------------------------------------------------------
| Attendance Dates
|--------------------------------------------------------------------------
*/

$attendanceDates = [];

if (
    $activeAcademicYear &&
    $gradeId > 0 &&
    $sectionId > 0
) {
    $academicYearId = (int) $activeAcademicYear['id'];

    $stmt = $conn->prepare("
        SELECT DISTINCT attendance_date
        FROM student_attendance
        WHERE academic_year_id = ?
          AND grade_id = ?
          AND section_id = ?
          AND attendance_date <= ?
        ORDER BY attendance_date DESC
        LIMIT 500
    ");

    $stmt->bind_param(
        'iiis',
        $academicYearId,
        $gradeId,
        $sectionId,
        $todayGregorian
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $date = (string) $row['attendance_date'];

        $ethiopian = EthiopianCalendar::fromGregorian($date);

        $attendanceDates[] = [
            'gregorian' => $date,
            'ethiopian' => $ethiopian,
        ];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Default To Most Recent Attendance Date
|--------------------------------------------------------------------------
*/

if (
    $selectedDate === '' &&
    !empty($attendanceDates)
) {
    $selectedDate = (string) $attendanceDates[0]['gregorian'];
}

/*
|--------------------------------------------------------------------------
| Validate Selected Date
|--------------------------------------------------------------------------
*/

$validSelectedDate = false;

if ($selectedDate !== '') {
    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $selectedDate,
        new DateTimeZone('Africa/Addis_Ababa')
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    $hasDateErrors =
        is_array($dateErrors) &&
        (
            $dateErrors['warning_count'] > 0 ||
            $dateErrors['error_count'] > 0
        );

    if (
        $dateObject !== false &&
        !$hasDateErrors &&
        $dateObject->format('Y-m-d') === $selectedDate
    ) {
        $validSelectedDate = true;
    }
}

/*
|--------------------------------------------------------------------------
| Future Date Protection
|--------------------------------------------------------------------------
*/

if (
    $validSelectedDate &&
    $selectedDate > $todayGregorian
) {
    $validSelectedDate = false;
    $errorMessage = 'Future dates cannot be edited.';
}

/*
|--------------------------------------------------------------------------
| Selected Date Must Exist In Attendance History
|--------------------------------------------------------------------------
*/

if (
    $validSelectedDate &&
    !empty($attendanceDates)
) {
    $dateExists = false;

    foreach ($attendanceDates as $attendanceDate) {
        if (
            $attendanceDate['gregorian'] ===
            $selectedDate
        ) {
            $dateExists = true;
            break;
        }
    }

    if (!$dateExists) {
        $validSelectedDate = false;
        $errorMessage = 'The selected attendance date was not found.';
    }
}

/*
|--------------------------------------------------------------------------
| POST - Update Attendance
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedClassId = isset($_POST['class'])
        ? (int) $_POST['class']
        : 0;

    $postedDate = isset($_POST['date'])
        ? trim((string) $_POST['date'])
        : '';

    $postedStatuses = isset($_POST['status']) &&
        is_array($_POST['status'])
        ? $_POST['status']
        : [];

    /*
    |--------------------------------------------------------------------------
    | Verify Class Belongs To Teacher
    |--------------------------------------------------------------------------
    */

    $postedAssignment = null;

    foreach ($homeroomAssignments as $assignment) {
        if (
            (int) $assignment['id'] ===
            $postedClassId
        ) {
            $postedAssignment = $assignment;
            break;
        }
    }

    if (!$postedAssignment) {
        $errorMessage = 'You are not authorized to edit this class.';
    }

    /*
    |--------------------------------------------------------------------------
    | Validate Posted Date
    |--------------------------------------------------------------------------
    */

    $postedDateObject = false;

    if ($errorMessage === '') {

        $postedDateObject = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $postedDate,
            new DateTimeZone('Africa/Addis_Ababa')
        );

        $dateErrors = DateTimeImmutable::getLastErrors();

        $hasDateErrors =
            is_array($dateErrors) &&
            (
                $dateErrors['warning_count'] > 0 ||
                $dateErrors['error_count'] > 0
            );

        if (
            $postedDateObject === false ||
            $hasDateErrors ||
            $postedDateObject->format('Y-m-d') !== $postedDate
        ) {
            $errorMessage = 'Invalid attendance date.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Future Date Protection
    |--------------------------------------------------------------------------
    */

    if (
        $errorMessage === '' &&
        $postedDate > $todayGregorian
    ) {
        $errorMessage = 'Future dates cannot be edited.';
    }

    /*
    |--------------------------------------------------------------------------
    | Resolve Posted Grade / Section
    |--------------------------------------------------------------------------
    */

    $postedGradeId = 0;
    $postedSectionId = 0;

    if ($errorMessage === '') {

        $postedGradeNumber =
            (int) $postedAssignment['grade'];

        $postedSectionCode =
            (string) $postedAssignment['section'];

        $stmt = $conn->prepare("
            SELECT
                g.id AS grade_id,
                sec.id AS section_id
            FROM grades g
            INNER JOIN sections sec
                ON sec.code = ?
            WHERE g.grade_number = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'si',
            $postedSectionCode,
            $postedGradeNumber
        );

        $stmt->execute();

        $result = $stmt->get_result();
        $postedGradeSection = $result->fetch_assoc();

        $stmt->close();

        if (!$postedGradeSection) {
            $errorMessage = 'Unable to resolve the selected class.';
        } else {
            $postedGradeId =
                (int) $postedGradeSection['grade_id'];

            $postedSectionId =
                (int) $postedGradeSection['section_id'];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Verify Attendance Day Exists
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

        $academicYearId =
            (int) $activeAcademicYear['id'];

        $stmt = $conn->prepare("
            SELECT id
            FROM student_attendance
            WHERE academic_year_id = ?
              AND grade_id = ?
              AND section_id = ?
              AND attendance_date = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'iiis',
            $academicYearId,
            $postedGradeId,
            $postedSectionId,
            $postedDate
        );

        $stmt->execute();

        $result = $stmt->get_result();
        $attendanceDay = $result->fetch_assoc();

        $stmt->close();

        if (!$attendanceDay) {
            $errorMessage =
                'No attendance record exists for the selected date.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Get Students Registered For This Class
    |--------------------------------------------------------------------------
    */

    $postStudents = [];

    if ($errorMessage === '') {

        $academicYearId =
            (int) $activeAcademicYear['id'];

        $stmt = $conn->prepare("
            SELECT
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
            LIMIT 5000
        ");

        $stmt->bind_param(
            'iii',
            $academicYearId,
            $postedGradeId,
            $postedSectionId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $postStudents[] = $row;
        }

        $stmt->close();

        if (empty($postStudents)) {
            $errorMessage =
                'No students are registered in the selected class.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Update Attendance
    |--------------------------------------------------------------------------
    */

    if ($errorMessage === '') {

        $ethiopianDate =
            EthiopianCalendar::fromGregorian($postedDate);

        $ethiopianYear =
            (int) ($ethiopianDate['year'] ?? 0);

        $ethiopianMonth =
            (int) ($ethiopianDate['month'] ?? 0);

        $ethiopianDay =
            (int) ($ethiopianDate['day'] ?? 0);

        $ethiopianDateText =
            (string) (
                $ethiopianDate['formatted'] ??
                $postedDate
            );

        $validStatuses = [
            'Present',
            'Absent',
            'Late',
            'Excused',
        ];

        $updateSucceeded = false;

        try {

            $conn->begin_transaction();

            /*
            |--------------------------------------------------------------------------
            | Update Existing Attendance
            |--------------------------------------------------------------------------
            */

            $updateStmt = $conn->prepare("
                UPDATE student_attendance
                SET
                    status = ?,
                    updated_by = ?
                WHERE registration_id = ?
                  AND attendance_date = ?
                  AND academic_year_id = ?
                  AND grade_id = ?
                  AND section_id = ?
            ");

            /*
            |--------------------------------------------------------------------------
            | Insert Missing Attendance
            |--------------------------------------------------------------------------
            |
            | Normally edit.php works with existing records.
            | This fallback keeps the page safe if one student is
            | unexpectedly missing a record for the selected date.
            |
            */

            $insertStmt = $conn->prepare("
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
                    ?
                )
            ");

            foreach ($postStudents as $student) {

                $registrationId =
                    (int) $student['registration_id'];

                $studentId =
                    (int) $student['student_id'];

                $status =
                    isset($postedStatuses[$registrationId])
                    ? trim((string) $postedStatuses[$registrationId])
                    : 'Present';

                if (!in_array(
                    $status,
                    $validStatuses,
                    true
                )) {
                    throw new RuntimeException(
                        'Invalid attendance status submitted.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Check By Registration ID
                |--------------------------------------------------------------------------
                */

                $existingAttendanceId = null;

                $existingStmt = $conn->prepare("
                    SELECT id
                    FROM student_attendance
                    WHERE registration_id = ?
                      AND attendance_date = ?
                      AND academic_year_id = ?
                      AND grade_id = ?
                      AND section_id = ?
                    LIMIT 1
                ");

                $existingStmt->bind_param(
                    'isiii',
                    $registrationId,
                    $postedDate,
                    $academicYearId,
                    $postedGradeId,
                    $postedSectionId
                );

                $existingStmt->execute();

                $existingResult =
                    $existingStmt->get_result();

                $existingRow =
                    $existingResult->fetch_assoc();

                $existingStmt->close();

                if ($existingRow) {

                    $existingAttendanceId =
                        (int) $existingRow['id'];

                    $updateStmt->bind_param(
                        'siisiii',
                        $status,
                        $teacherUserId,
                        $registrationId,
                        $postedDate,
                        $academicYearId,
                        $postedGradeId,
                        $postedSectionId
                    );

                    $updateStmt->execute();

                } else {

                    $insertStmt->bind_param(
                        'iiiiisiiissii',
                        $studentId,
                        $registrationId,
                        $academicYearId,
                        $postedGradeId,
                        $postedSectionId,
                        $postedDate,
                        $ethiopianYear,
                        $ethiopianMonth,
                        $ethiopianDay,
                        $ethiopianDateText,
                        $status,
                        $teacherUserId,
                        $teacherUserId
                    );

                    $insertStmt->execute();
                }
            }

            $updateStmt->close();
            $insertStmt->close();

            $conn->commit();

            $updateSucceeded = true;

        } catch (Throwable $e) {

            $conn->rollback();

            error_log(
                'BKHS attendance edit error: ' .
                $e->getMessage()
            );

            $errorMessage =
                'Unable to update attendance. Please try again.';
        }

        /*
        |--------------------------------------------------------------------------
        | Reload Page After Successful Update
        |--------------------------------------------------------------------------
        */

        if ($updateSucceeded) {

            $successMessage =
                'Attendance updated successfully.';

            $selectedAssignmentId =
                $postedClassId;

            $selectedAssignment =
                $postedAssignment;

            $gradeId =
                $postedGradeId;

            $sectionId =
                $postedSectionId;

            $selectedDate =
                $postedDate;

            /*
            |--------------------------------------------------------------------------
            | Reload Existing Attendance Map
            |--------------------------------------------------------------------------
            */

            $attendanceMap = [];

            $stmt = $conn->prepare("
                SELECT
                    registration_id,
                    status
                FROM student_attendance
                WHERE academic_year_id = ?
                  AND grade_id = ?
                  AND section_id = ?
                  AND attendance_date = ?
            ");

            $stmt->bind_param(
                'iiis',
                $academicYearId,
                $gradeId,
                $sectionId,
                $selectedDate
            );

            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {

                $attendanceMap[
                    (int) $row['registration_id']
                ] = (string) $row['status'];
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Students For Selected Class / Date
|--------------------------------------------------------------------------
*/

$students = [];
$attendanceMap = $attendanceMap ?? [];

if (
    $activeAcademicYear &&
    $selectedAssignment &&
    $gradeId > 0 &&
    $sectionId > 0 &&
    $validSelectedDate
) {

    $academicYearId =
        (int) $activeAcademicYear['id'];

    /*
    |--------------------------------------------------------------------------
    | Load Students
    |--------------------------------------------------------------------------
    */

    $stmt = $conn->prepare("
        SELECT
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
        LIMIT 5000
    ");

    $stmt->bind_param(
        'iii',
        $academicYearId,
        $gradeId,
        $sectionId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }

    $stmt->close();

    /*
    |--------------------------------------------------------------------------
    | Load Attendance By Registration ID
    |--------------------------------------------------------------------------
    */

    $attendanceMap = [];

    $stmt = $conn->prepare("
        SELECT
            registration_id,
            status
        FROM student_attendance
        WHERE academic_year_id = ?
          AND grade_id = ?
          AND section_id = ?
          AND attendance_date = ?
    ");

    $stmt->bind_param(
        'iiis',
        $academicYearId,
        $gradeId,
        $sectionId,
        $selectedDate
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $attendanceMap[
            (int) $row['registration_id']
        ] = (string) $row['status'];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Class Name
|--------------------------------------------------------------------------
*/

$selectedClassName = '';

if ($selectedAssignment) {

    $gradeName = trim(
        (string) (
            $selectedAssignment['grade_name']
            ??
            ('Grade ' . $selectedAssignment['grade'])
        )
    );

    $sectionName = trim(
        (string) (
            $selectedAssignment['section_name']
            ??
            ('Section ' . $selectedAssignment['section'])
        )
    );

    $selectedClassName =
        $gradeName . ' ' . $sectionName;
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
        content="Edit daily attendance - BKHS Teacher Portal"
    >

    <title>Edit Attendance | BKHS</title>

    <link
        rel="icon"
        type="image/webp"
        href="../../public/image/logo.webp"
    >

    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-soft: #eff6ff;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --text: #111827;
            --muted: #64748b;
            --border: #e5e7eb;
            --border-strong: #cbd5e1;
            --page-bg: #f8fafc;
            --white: #fff;
            --success: #16a34a;
            --danger: #dc2626;
            --warning: #d97706;
            --info: #0284c7;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--page-bg);
            color: var(--text);
            font-family: 'Inter', sans-serif;
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
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 22px 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .sidebar-brand img {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 10px;
            background: #fff;
        }

        .sidebar-brand strong {
            display: block;
            font-size: 16px;
        }

        .sidebar-brand span {
            display: block;
            margin-top: 2px;
            color: #94a3b8;
            font-size: 12px;
        }

        .sidebar-nav {
            padding: 18px 12px 24px;
        }

        .sidebar-section-title {
            padding: 10px 10px 7px;
            color: #64748b;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 11px;
            min-height: 44px;
            margin-bottom: 4px;
            padding: 10px 12px;
            border-radius: 9px;
            color: #cbd5e1;
            font-size: 13px;
            font-weight: 500;
            transition: .2s ease;
        }

        .sidebar-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }

        .sidebar-link.active {
            background: var(--primary);
            color: #fff;
        }

        .sidebar-link i {
            width: 20px;
            text-align: center;
            font-size: 17px;
        }

        .main {
            min-height: 100vh;
            margin-left: 260px;
        }

        .topbar {
            position: sticky;
            top: 0;
            height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
            background: rgba(255,255,255,.96);
            border-bottom: 1px solid var(--border);
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
        }

        .teacher-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .teacher-profile img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
        }

        .teacher-profile-info strong {
            display: block;
            font-size: 13px;
        }

        .teacher-profile-info span {
            display: block;
            color: var(--muted);
            font-size: 11px;
        }

        .page-content {
            padding: 26px 28px 100px;
        }

        .mobile-dashboard {
            display: none;
        }

        .attendance-nav {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-bottom: 22px;
            padding: 5px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .attendance-nav a {
            padding: 9px 13px;
            border-radius: 8px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .attendance-nav a:hover {
            background: var(--primary-soft);
            color: var(--primary);
        }

        .attendance-nav a.active {
            background: var(--primary);
            color: #fff;
        }

        .page-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .page-heading h1 {
            margin: 0;
            font-size: 25px;
            font-weight: 800;
        }

        .page-heading p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .academic-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 12px;
            background: var(--primary-soft);
            color: var(--primary-dark);
            border: 1px solid #dbeafe;
            border-radius: 9px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .filter-card {
            padding: 20px;
            margin-bottom: 20px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 15px;
            align-items: end;
        }

        .form-label {
            margin-bottom: 7px;
            color: #334155;
            font-size: 12px;
            font-weight: 700;
        }

        .form-select {
            min-height: 43px;
            border-color: var(--border-strong);
            font-size: 13px;
        }

        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 .2rem rgba(37,99,235,.12);
        }

        .load-button,
        .save-button {
            min-height: 43px;
            padding: 10px 17px;
            border: 0;
            border-radius: 9px;
            background: var(--primary);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
        }

        .load-button:hover,
        .save-button:hover {
            background: var(--primary-dark);
        }

        .attendance-info {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }

        .info-box {
            padding: 16px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
        }

        .info-box-label {
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 600;
        }

        .info-box-value {
            color: var(--text);
            font-size: 14px;
            font-weight: 800;
        }

        .attendance-card {
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(15,23,42,.03);
        }

        .attendance-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .attendance-card-header h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
        }

        .attendance-card-header p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 11px;
        }

        .status-legend {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .legend-item {
            padding: 5px 8px;
            border-radius: 6px;
            background: #f8fafc;
            color: #475569;
            font-size: 10px;
            font-weight: 600;
        }

        .attendance-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .attendance-table {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
        }

        .attendance-table th,
        .attendance-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }

        .attendance-table th {
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .attendance-table td {
            color: #334155;
            font-size: 12px;
        }

        .number-column {
            width: 60px;
            text-align: center;
        }

        .student-column {
            min-width: 230px;
        }

        .status-column {
            width: 120px;
            text-align: center;
        }

        .status-option {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 92px;
            padding: 7px 8px;
            border: 1px solid transparent;
            border-radius: 7px;
            cursor: pointer;
            color: #64748b;
            font-size: 11px;
            font-weight: 600;
            transition: .15s ease;
        }

        .status-option:hover {
            background: #f8fafc;
        }

        .status-option.selected {
            background: var(--primary-soft);
            border-color: #bfdbfe;
            color: var(--primary-dark);
        }

        .status-option input {
            margin: 0;
            accent-color: var(--primary);
        }

        .save-bar {
            display: flex;
            justify-content: flex-end;
            padding: 16px 20px;
            background: #fff;
        }

        .empty-state {
            padding: 55px 25px;
            text-align: center;
        }

        .empty-state-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 62px;
            height: 62px;
            margin: 0 auto 15px;
            border-radius: 50%;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: 26px;
        }

        .empty-state h3 {
            margin: 0 0 7px;
            font-size: 17px;
            font-weight: 800;
        }

        .empty-state p {
            max-width: 520px;
            margin: 0 auto;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .mobile-bottom-nav {
            display: none;
        }

        @media (max-width: 991px) {

            .sidebar {
                display: none;
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                height: 64px;
                padding: 0 16px;
            }

            .topbar-title {
                font-size: 16px;
            }

            .teacher-profile-info {
                display: none;
            }

            .page-content {
                padding: 18px 14px 92px;
            }

            .mobile-dashboard {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                margin-bottom: 14px;
                padding: 8px 11px;
                background: #fff;
                border: 1px solid var(--border);
                border-radius: 8px;
                color: var(--primary);
                font-size: 11px;
                font-weight: 700;
            }

            .attendance-nav {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                width: 100%;
                margin-bottom: 18px;
            }

            .attendance-nav a {
                padding: 9px 5px;
                text-align: center;
                font-size: 10px;
            }

            .page-heading {
                flex-direction: column;
                gap: 12px;
                margin-bottom: 18px;
            }

            .page-heading h1 {
                font-size: 21px;
            }

            .page-heading p {
                line-height: 1.5;
            }

            .academic-badge {
                font-size: 10px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-card {
                padding: 15px;
            }

            .load-button {
                width: 100%;
            }

            .attendance-info {
                grid-template-columns: repeat(2, 1fr);
            }

            .attendance-card-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .status-legend {
                width: 100%;
            }

            .save-button {
                width: 100%;
            }

            .save-bar {
                padding: 14px;
            }

            .mobile-bottom-nav {
                position: fixed;
                right: 0;
                bottom: 0;
                left: 0;
                z-index: 1100;
                display: grid;
                grid-template-columns: repeat(5, 1fr);
                height: 66px;
                background: #fff;
                border-top: 1px solid var(--border);
                box-shadow: 0 -3px 15px rgba(15,23,42,.08);
            }

            .mobile-bottom-link {
                display: flex;
                align-items: center;
                justify-content: center;
                flex-direction: column;
                gap: 4px;
                color: #64748b;
                font-size: 9px;
                font-weight: 600;
            }

            .mobile-bottom-link i {
                font-size: 18px;
            }

            .mobile-bottom-link:hover {
                color: var(--primary);
            }
        }

        @media (max-width: 480px) {

            .page-content {
                padding-right: 10px;
                padding-left: 10px;
            }

            .attendance-nav a {
                font-size: 9px;
            }

            .attendance-info {
                gap: 9px;
            }

            .info-box {
                padding: 12px;
            }

            .info-box-value {
                font-size: 12px;
            }

            .attendance-card-header {
                padding: 15px;
            }

            .attendance-table th,
            .attendance-table td {
                padding: 10px 8px;
            }

            .status-option {
                min-width: 82px;
                padding: 6px 5px;
                font-size: 10px;
            }

            .mobile-bottom-link {
                font-size: 8px;
            }

            .mobile-bottom-link i {
                font-size: 17px;
            }
        }

    </style>

</head>

<body>

    <aside class="sidebar">

        <div class="sidebar-brand">

            <img
                src="../../public/image/logo.webp"
                alt="BKHS Logo"
            >

            <div>
                <strong>BKHS</strong>
                <span>Teacher Portal</span>
            </div>

        </div>

        <nav class="sidebar-nav">

            <div class="sidebar-section-title">
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
                <span>Result</span>
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
                <i class="bi bi-clipboard-check"></i>
                <span>Subject Attendance</span>
            </a>

            <a
                href="../homework.php"
                class="sidebar-link"
            >
                <i class="bi bi-journal-text"></i>
                <span>Homework</span>
            </a>

            <a
                href="../announcements.php"
                class="sidebar-link"
            >
                <i class="bi bi-megaphone"></i>
                <span>Announcements</span>
            </a>

            <a
                href="../roster.php"
                class="sidebar-link"
            >
                <i class="bi bi-person-lines-fill"></i>
                <span>Roster</span>
            </a>

            <div class="sidebar-section-title">
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
                class="sidebar-link"
            >
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </a>

        </nav>

    </aside>

    <main class="main">

        <header class="topbar">

            <div class="topbar-title">
                Teacher Portal
            </div>

            <div class="teacher-profile">

                <div class="teacher-profile-info">
                    <strong>
                        <?= h($teacher['full_name']) ?>
                    </strong>

                    <span>
                        Teacher
                    </span>
                </div>

                <img
                    src="<?= h($teacherPhoto) ?>"
                    alt="Teacher"
                >

            </div>

        </header>

        <div class="page-content">

            <a
                href="../dashboard.php"
                class="mobile-dashboard"
            >
                <i class="bi bi-grid-1x2-fill"></i>
                Dashboard
            </a>

            <div class="attendance-nav">

                <a href="../daily-attendance.php">
                    Create Attendance
                </a>

                <a href="history.php">
                    Attendance History
                </a>

                <a
                    href="edit.php"
                    class="active"
                >
                    Edit Attendance
                </a>

            </div>

            <div class="page-heading">

                <div>

                    <h1>
                        Edit Daily Attendance
                    </h1>

                    <p>
                        Select a class and an existing attendance
                        date, then update student attendance status.
                    </p>

                </div>

                <?php if ($activeAcademicYear): ?>

                    <div class="academic-badge">

                        <i class="bi bi-calendar3"></i>

                        <?= h($activeAcademicYear['name']) ?>

                    </div>

                <?php endif; ?>

            </div>

            <?php if ($successMessage !== ''): ?>

                <div
                    class="alert alert-success"
                    role="alert"
                >
                    <i class="bi bi-check-circle me-1"></i>
                    <?= h($successMessage) ?>
                </div>

            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>

                <div
                    class="alert alert-danger"
                    role="alert"
                >
                    <i class="bi bi-exclamation-circle me-1"></i>
                    <?= h($errorMessage) ?>
                </div>

            <?php endif; ?>

            <?php if (!$activeAcademicYear): ?>

                <div class="attendance-card">

                    <div class="empty-state">

                        <div class="empty-state-icon">
                            <i class="bi bi-calendar-x"></i>
                        </div>

                        <h3>
                            No Active Academic Year
                        </h3>

                        <p>
                            Attendance cannot be edited because
                            there is no active academic year.
                        </p>

                    </div>

                </div>

            <?php elseif (empty($homeroomAssignments)): ?>

                <div class="attendance-card">

                    <div class="empty-state">

                        <div class="empty-state-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <h3>
                            No Assigned Classes
                        </h3>

                        <p>
                            You do not have an active homeroom
                            class assignment for the current
                            academic year.
                        </p>

                    </div>

                </div>

            <?php else: ?>

                <form
                    method="get"
                    action="edit.php"
                    class="filter-card"
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
                                onchange="this.form.submit()"
                            >

                                <?php foreach (
                                    $homeroomAssignments
                                    as $assignment
                                ): ?>

                                    <?php
                                    $assignmentGrade =
                                        trim(
                                            (string) (
                                                $assignment['grade_name']
                                                ??
                                                (
                                                    'Grade ' .
                                                    $assignment['grade']
                                                )
                                            )
                                        );

                                    $assignmentSection =
                                        trim(
                                            (string) (
                                                $assignment['section_name']
                                                ??
                                                (
                                                    'Section ' .
                                                    $assignment['section']
                                                )
                                            )
                                        );

                                    $assignmentLabel =
                                        $assignmentGrade .
                                        ' ' .
                                        $assignmentSection;
                                    ?>

                                    <option
                                        value="<?= (int) $assignment['id'] ?>"
                                        <?= (
                                            (int) $assignment['id'] ===
                                            $selectedAssignmentId
                                        )
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= h($assignmentLabel) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div>

                            <label
                                for="date"
                                class="form-label"
                            >
                                Attendance Date
                            </label>

                            <select
                                name="date"
                                id="date"
                                class="form-select"
                            >

                                <?php if (
                                    empty($attendanceDates)
                                ): ?>

                                    <option value="">
                                        No attendance history
                                    </option>

                                <?php else: ?>

                                    <?php foreach (
                                        $attendanceDates
                                        as $attendanceDate
                                    ): ?>

                                        <?php
                                        $dateEthiopian =
                                            $attendanceDate['ethiopian'];
                                        ?>

                                        <option
                                            value="<?= h($attendanceDate['gregorian']) ?>"
                                            <?= (
                                                $attendanceDate['gregorian'] ===
                                                $selectedDate
                                            )
                                                ? 'selected'
                                                : '' ?>
                                        >
                                            <?= h(
                                                $dateEthiopian['day_name'] .
                                                ', ' .
                                                $dateEthiopian['formatted']
                                            ) ?>
                                        </option>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </select>

                        </div>

                        <div>

                            <button
                                type="submit"
                                class="load-button"
                                <?= empty($attendanceDates)
                                    ? 'disabled'
                                    : '' ?>
                            >
                                <i class="bi bi-arrow-clockwise"></i>
                                Load Attendance
                            </button>

                        </div>

                    </div>

                </form>

                <?php if (
                    $validSelectedDate &&
                    $selectedAssignment
                ): ?>

                    <?php
                    $selectedEthiopian =
                        EthiopianCalendar::fromGregorian(
                            $selectedDate
                        );
                    ?>

                    <div class="attendance-info">

                        <div class="info-box">

                            <div class="info-box-label">
                                Academic Year
                            </div>

                            <div class="info-box-value">
                                <?= h(
                                    $activeAcademicYear['name']
                                ) ?>
                            </div>

                        </div>

                        <div class="info-box">

                            <div class="info-box-label">
                                Class
                            </div>

                            <div class="info-box-value">
                                <?= h(
                                    $selectedClassName
                                ) ?>
                            </div>

                        </div>

                        <div class="info-box">

                            <div class="info-box-label">
                                Attendance Date
                            </div>

                            <div class="info-box-value">
                                <?= h(
                                    $selectedEthiopian['formatted']
                                ) ?>
                            </div>

                        </div>

                        <div class="info-box">

                            <div class="info-box-label">
                                Students
                            </div>

                            <div class="info-box-value">
                                <?= count($students) ?>
                            </div>

                        </div>

                    </div>

                    <div class="attendance-card">

                        <div class="attendance-card-header">

                            <div>

                                <h2>
                                    <?= h($selectedClassName) ?>
                                </h2>

                                <p>
                                    Update the attendance status
                                    for each student.
                                </p>

                            </div>

                            <div class="status-legend">

                                <span class="legend-item">
                                    Present
                                </span>

                                <span class="legend-item">
                                    Absent
                                </span>

                                <span class="legend-item">
                                    Late
                                </span>

                                <span class="legend-item">
                                    Excused
                                </span>

                            </div>

                        </div>

                        <?php if (empty($students)): ?>

                            <div class="empty-state">

                                <div class="empty-state-icon">
                                    <i class="bi bi-people"></i>
                                </div>

                                <h3>
                                    No Students Found
                                </h3>

                                <p>
                                    There are no students registered
                                    in this class for the active
                                    academic year.
                                </p>

                            </div>

                        <?php else: ?>

                            <form
                                method="post"
                                action="<?= h(
                                    editAttendanceUrl(
                                        $selectedAssignmentId,
                                        $selectedDate
                                    )
                                ) ?>"
                                id="attendanceForm"
                            >

                                <input
                                    type="hidden"
                                    name="class"
                                    value="<?= $selectedAssignmentId ?>"
                                >

                                <input
                                    type="hidden"
                                    name="date"
                                    value="<?= h($selectedDate) ?>"
                                >

                                <div class="attendance-table-wrapper">

                                    <table class="attendance-table">

                                        <thead>

                                            <tr>

                                                <th
                                                    class="number-column"
                                                >
                                                    No.
                                                </th>

                                                <th
                                                    class="student-column"
                                                >
                                                    Student Name
                                                </th>

                                                <th
                                                    class="status-column"
                                                >
                                                    Present
                                                </th>

                                                <th
                                                    class="status-column"
                                                >
                                                    Absent
                                                </th>

                                                <th
                                                    class="status-column"
                                                >
                                                    Late
                                                </th>

                                                <th
                                                    class="status-column"
                                                >
                                                    Excused
                                                </th>

                                            </tr>

                                        </thead>

                                        <tbody>

                                            <?php foreach (
                                                $students
                                                as $index =>
                                                $student
                                            ): ?>

                                                <?php

                                                $registrationId =
                                                    (int)
                                                    $student[
                                                        'registration_id'
                                                    ];

                                                $currentStatus =
                                                    $attendanceMap[
                                                        $registrationId
                                                    ]
                                                    ??
                                                    'Present';

                                                ?>

                                                <tr>

                                                    <td
                                                        class="number-column"
                                                    >
                                                        <?= $index + 1 ?>
                                                    </td>

                                                    <td
                                                        class="student-column"
                                                    >
                                                        <?= h(
                                                            $student[
                                                                'full_name'
                                                            ]
                                                        ) ?>
                                                    </td>

                                                    <td
                                                        class="status-column"
                                                    >

                                                        <label
                                                            class="status-option <?= $currentStatus === 'Present'
                                                                ? 'selected'
                                                                : '' ?>"
                                                        >

                                                            <input
                                                                type="radio"
                                                                name="status[<?= $registrationId ?>]"
                                                                value="Present"
                                                                <?= $currentStatus === 'Present'
                                                                    ? 'checked'
                                                                    : '' ?>
                                                            >

                                                            <span>
                                                                Present
                                                            </span>

                                                        </label>

                                                    </td>

                                                    <td
                                                        class="status-column"
                                                    >

                                                        <label
                                                            class="status-option <?= $currentStatus === 'Absent'
                                                                ? 'selected'
                                                                : '' ?>"
                                                        >

                                                            <input
                                                                type="radio"
                                                                name="status[<?= $registrationId ?>]"
                                                                value="Absent"
                                                                <?= $currentStatus === 'Absent'
                                                                    ? 'checked'
                                                                    : '' ?>
                                                            >

                                                            <span>
                                                                Absent
                                                            </span>

                                                        </label>

                                                    </td>

                                                    <td
                                                        class="status-column"
                                                    >

                                                        <label
                                                            class="status-option <?= $currentStatus === 'Late'
                                                                ? 'selected'
                                                                : '' ?>"
                                                        >

                                                            <input
                                                                type="radio"
                                                                name="status[<?= $registrationId ?>]"
                                                                value="Late"
                                                                <?= $currentStatus === 'Late'
                                                                    ? 'checked'
                                                                    : '' ?>
                                                            >

                                                            <span>
                                                                Late
                                                            </span>

                                                        </label>

                                                    </td>

                                                    <td
                                                        class="status-column"
                                                    >

                                                        <label
                                                            class="status-option <?= $currentStatus === 'Excused'
                                                                ? 'selected'
                                                                : '' ?>"
                                                        >

                                                            <input
                                                                type="radio"
                                                                name="status[<?= $registrationId ?>]"
                                                                value="Excused"
                                                                <?= $currentStatus === 'Excused'
                                                                    ? 'checked'
                                                                    : '' ?>
                                                            >

                                                            <span>
                                                                Excused
                                                            </span>

                                                        </label>

                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                </div>

                                <div class="save-bar">

                                    <button
                                        type="submit"
                                        class="save-button"
                                    >
                                        <i
                                            class="bi bi-check2-circle"
                                        ></i>
                                        Update Attendance
                                    </button>

                                </div>

                            </form>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <div class="attendance-card">

                        <div class="empty-state">

                            <div class="empty-state-icon">
                                <i
                                    class="bi bi-calendar2-check"
                                ></i>
                            </div>

                            <?php if (
                                empty($attendanceDates)
                            ): ?>

                                <h3>
                                    No Attendance History
                                </h3>

                                <p>
                                    There is no previously recorded
                                    attendance for
                                    <?= h(
                                        $selectedClassName
                                    ) ?>
                                    in the active academic year.
                                </p>

                            <?php else: ?>

                                <h3>
                                    Select Attendance to Edit
                                </h3>

                                <p>
                                    Choose an existing attendance
                                    date above to load the students
                                    and update their attendance
                                    status.
                                </p>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </main>

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

    <script>

        document.addEventListener(
            'DOMContentLoaded',
            function () {

                const radios =
                    document.querySelectorAll(
                        '.status-option input[type="radio"]'
                    );

                radios.forEach(function (radio) {

                    radio.addEventListener(
                        'change',
                        function () {

                            const name =
                                radio.getAttribute('name');

                            if (!name) {
                                return;
                            }

                            document
                                .querySelectorAll(
                                    'input[name="' +
                                    name +
                                    '"]'
                                )
                                .forEach(
                                    function (item) {

                                        const label =
                                            item.closest(
                                                '.status-option'
                                            );

                                        if (label) {
                                            label.classList
                                                .remove(
                                                    'selected'
                                                );
                                        }

                                    }
                                );

                            const currentLabel =
                                radio.closest(
                                    '.status-option'
                                );

                            if (currentLabel) {
                                currentLabel.classList
                                    .add('selected');
                            }

                        }
                    );

                });

            }
        );

    </script>

</body>

</html>

