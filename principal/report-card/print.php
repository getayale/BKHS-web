<?php

declare(strict_types=1);

session_start();

/*
|--------------------------------------------------------------------------
| Principal Authentication
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function numberFormat(float $value): string
{
    if (floor($value) === $value) {
        return number_format($value, 0);
    }

    return number_format($value, 2);
}

/*
|--------------------------------------------------------------------------
| Database Check
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection is not available.');
}

/*
|--------------------------------------------------------------------------
| Validate Parameters
|--------------------------------------------------------------------------
*/

$academicYearId = isset($_GET['academic_year_id'])
    ? (int) $_GET['academic_year_id']
    : 0;

$gradeId = isset($_GET['grade_id'])
    ? (int) $_GET['grade_id']
    : 0;

$sectionId = isset($_GET['section_id'])
    ? (int) $_GET['section_id']
    : 0;

$semesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

if (
    $academicYearId <= 0 ||
    $gradeId <= 0 ||
    $sectionId <= 0 ||
    $semesterId <= 0
) {
    die('Invalid report card parameters.');
}

/*
|--------------------------------------------------------------------------
| Load Academic Year
|--------------------------------------------------------------------------
*/

$academicYear = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        status
    FROM academic_years
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $academicYear = $result->fetch_assoc();
}

$stmt->close();

if (!$academicYear) {
    die('Academic year not found.');
}

/*
|--------------------------------------------------------------------------
| Load Grade
|--------------------------------------------------------------------------
*/

$grade = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        grade_number
    FROM grades
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $gradeId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $grade = $result->fetch_assoc();
}

$stmt->close();

if (!$grade) {
    die('Grade not found.');
}

/*
|--------------------------------------------------------------------------
| Grade Number
|--------------------------------------------------------------------------
|
| grade_subjects.grade stores the grade number.
|
*/

$gradeNumber = (int) $grade['grade_number'];

if ($gradeNumber <= 0) {
    die('Invalid grade number.');
}

/*
|--------------------------------------------------------------------------
| Load Section
|--------------------------------------------------------------------------
*/

$section = null;

$stmt = $conn->prepare("
    SELECT
        id,
        name,
        code
    FROM sections
    WHERE id = ?
    LIMIT 1
");

$stmt->bind_param('i', $sectionId);
$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $section = $result->fetch_assoc();
}

$stmt->close();

if (!$section) {
    die('Section not found.');
}

/*
|--------------------------------------------------------------------------
| Section Display
|--------------------------------------------------------------------------
*/

$sectionDisplay = trim((string) $section['code']);

if ($sectionDisplay === '') {

    $sectionDisplay = trim((string) $section['name']);

    $sectionDisplay = preg_replace(
        '/^section\s+/i',
        '',
        $sectionDisplay
    );

    $sectionDisplay = trim((string) $sectionDisplay);
}

/*
|--------------------------------------------------------------------------
| Load Semester
|--------------------------------------------------------------------------
*/

$semester = null;

$stmt = $conn->prepare("
    SELECT
        id,
        academic_year_id,
        name,
        order_number,
        max_mark,
        status
    FROM semesters
    WHERE id = ?
      AND academic_year_id = ?
    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $semesterId,
    $academicYearId
);

$stmt->execute();

$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $semester = $result->fetch_assoc();
}

$stmt->close();

if (!$semester) {
    die('Semester not found for the selected academic year.');
}

/*
|--------------------------------------------------------------------------
| Determine Grade Column
|--------------------------------------------------------------------------
*/

$semesterNameLower = strtolower(
    trim((string) $semester['name'])
);

$showGradeColumn = in_array(
    $semesterNameLower,
    [
        'first semester',
        'second semester'
    ],
    true
);

/*
|--------------------------------------------------------------------------
| Load Students
|--------------------------------------------------------------------------
*/

$students = [];

$stmt = $conn->prepare("
    SELECT DISTINCT
        s.id,
        s.student_code,
        s.full_name,
        sr.id AS registration_id
    FROM student_registrations sr
    INNER JOIN students s
        ON s.id = sr.student_id
    INNER JOIN results r
        ON r.student_registration_id = sr.id
    WHERE sr.academic_year_id = ?
      AND sr.grade_id = ?
      AND sr.section_id = ?
      AND r.semester_id = ?
      AND s.is_deleted = 0
    ORDER BY
        s.full_name ASC,
        s.id ASC
");

$stmt->bind_param(
    'iiii',
    $academicYearId,
    $gradeId,
    $sectionId,
    $semesterId
);

$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Load Subjects
|--------------------------------------------------------------------------
*/

$subjects = [];

$stmt = $conn->prepare("
    SELECT
        gs.id,
        gs.subject_name
    FROM grade_subjects gs
    WHERE gs.grade = ?
      AND gs.is_active = 1
    ORDER BY gs.id ASC
");

$stmt->bind_param(
    'i',
    $gradeNumber
);

$stmt->execute();

$subjectResult = $stmt->get_result();

while ($row = $subjectResult->fetch_assoc()) {
    $subjects[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Load Result Marks
|--------------------------------------------------------------------------
*/

$studentResults = [];

if (!empty($students)) {

    $stmt = $conn->prepare("
        SELECT
            r.student_registration_id,
            r.grade_subject_id,
            r.mark
        FROM results r
        INNER JOIN student_registrations sr
            ON sr.id = r.student_registration_id
        WHERE sr.academic_year_id = ?
          AND sr.grade_id = ?
          AND sr.section_id = ?
          AND r.semester_id = ?
    ");

    $stmt->bind_param(
        'iiii',
        $academicYearId,
        $gradeId,
        $sectionId,
        $semesterId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $registrationId =
            (int) $row['student_registration_id'];

        $subjectId =
            (int) $row['grade_subject_id'];

        $studentResults[$registrationId][$subjectId] =
            $row['mark'] !== null
                ? (float) $row['mark']
                : null;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Calculate Student Totals
|--------------------------------------------------------------------------
*/

$studentTotals = [];

foreach ($students as $student) {

    $registrationId =
        (int) $student['registration_id'];

    $total = 0.0;

    foreach ($subjects as $subject) {

        $subjectId =
            (int) $subject['id'];

        if (
            isset(
                $studentResults[$registrationId][$subjectId]
            ) &&
            $studentResults[$registrationId][$subjectId] !== null
        ) {
            $total +=
                (float) $studentResults[$registrationId][$subjectId];
        }
    }

    $studentTotals[$registrationId] = $total;
}

/*
|--------------------------------------------------------------------------
| Sort Totals For Ranking
|--------------------------------------------------------------------------
*/

$rankTotals = $studentTotals;

arsort(
    $rankTotals,
    SORT_NUMERIC
);

/*
|--------------------------------------------------------------------------
| Assign Competition Ranking
|--------------------------------------------------------------------------
|
| Example:
|
| 1, 1, 3
|
*/

$studentRanks = [];

$rank = 0;
$position = 0;
$previousTotal = null;

foreach ($rankTotals as $registrationId => $total) {

    $position++;

    if (
        $previousTotal === null ||
        $total < $previousTotal
    ) {
        $rank = $position;
    }

    $studentRanks[$registrationId] = $rank;

    $previousTotal = $total;
}

/*
|--------------------------------------------------------------------------
| Principal / Director Information
|--------------------------------------------------------------------------
*/

$principalName =
    isset($_SESSION['full_name'])
        ? trim((string) $_SESSION['full_name'])
        : 'Director';

if ($principalName === '') {
    $principalName = 'Director';
}

$principalSignaturePath = null;

$principalUserId =
    (int) $_SESSION['user_id'];

try {

    $stmt = $conn->prepare("
        SELECT
            full_name,
            signature_path
        FROM users
        WHERE id = ?
          AND LOWER(role) = 'principal'
          AND is_deleted = 0
        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $principalUserId
    );

    $stmt->execute();

    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $principalRow =
            $result->fetch_assoc();

        if (
            isset($principalRow['full_name']) &&
            trim((string) $principalRow['full_name']) !== ''
        ) {
            $principalName =
                trim((string) $principalRow['full_name']);
        }

        if (
            isset($principalRow['signature_path']) &&
            trim((string) $principalRow['signature_path']) !== ''
        ) {
            $principalSignaturePath =
                trim((string) $principalRow['signature_path']);
        }
    }

    $stmt->close();

} catch (mysqli_sql_exception $exception) {

    // Keep session principal name.
}

/*
|--------------------------------------------------------------------------
| Principal Signature URL
|--------------------------------------------------------------------------
*/

$principalSignatureUrl = null;

if (
    $principalSignaturePath !== null &&
    $principalSignaturePath !== ''
) {
    $principalSignatureUrl =
        '../../' . ltrim(
            $principalSignaturePath,
            '/'
        );
}

/*
|--------------------------------------------------------------------------
| Homeroom Teacher Information
|--------------------------------------------------------------------------
*/

$teacherName = '';

$teacherSignaturePath = null;

$academicYearName =
    (string) $academicYear['name'];

$sectionCode =
    trim((string) $section['code']);

if ($sectionCode === '') {
    $sectionCode = $sectionDisplay;
}

try {

    $stmt = $conn->prepare("
        SELECT
            u.full_name,
            u.signature_path
        FROM homeroom_teacher_assignments hta
        INNER JOIN users u
            ON u.id = hta.teacher_user_id
        WHERE hta.academic_year = ?
          AND hta.grade = ?
          AND hta.section = ?
          AND hta.is_active = 1
          AND u.is_deleted = 0
        ORDER BY hta.id DESC
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            'sis',
            $academicYearName,
            $gradeNumber,
            $sectionCode
        );

        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $teacherRow =
                $result->fetch_assoc();

            if (
                isset($teacherRow['full_name']) &&
                trim((string) $teacherRow['full_name']) !== ''
            ) {
                $teacherName =
                    trim((string) $teacherRow['full_name']);
            }

            if (
                isset($teacherRow['signature_path']) &&
                trim((string) $teacherRow['signature_path']) !== ''
            ) {
                $teacherSignaturePath =
                    trim((string) $teacherRow['signature_path']);
            }
        }

        $stmt->close();
    }

} catch (mysqli_sql_exception $exception) {

    $teacherName = '';
}

/*
|--------------------------------------------------------------------------
| Teacher Signature URL
|--------------------------------------------------------------------------
*/

$teacherSignatureUrl = null;

if (
    $teacherSignaturePath !== null &&
    $teacherSignaturePath !== ''
) {
    $teacherSignatureUrl =
        '../../' . ltrim(
            $teacherSignaturePath,
            '/'
        );
}

/*
|--------------------------------------------------------------------------
| Empty State
|--------------------------------------------------------------------------
*/

if (empty($students)) {
    ?>

    <!DOCTYPE html>
    <html lang="en">

    <head>

        <meta charset="UTF-8">

        <meta
            name="viewport"
            content="width=device-width, initial-scale=1.0"
        >

        <title>No Report Cards</title>

        <link
            rel="icon"
            type="image/webp"
            href="../../public/logo.webp?v=1"
        >

        <link
            href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
            rel="stylesheet"
        >

        <style>

            body {
                background: #f5f7fb;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: Inter, Arial, sans-serif;
                margin: 0;
            }

            .empty-card {
                max-width: 500px;
                width: calc(100% - 30px);
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 18px;
                padding: 40px;
                text-align: center;
                box-shadow: 0 8px 30px rgba(15, 23, 42, .08);
            }

            .empty-icon {
                width: 70px;
                height: 70px;
                margin: 0 auto 20px;
                border-radius: 18px;
                background: #eef2ff;
                color: #4f46e5;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 32px;
            }

        </style>

    </head>

    <body>

        <div class="empty-card">

            <div class="empty-icon">
                <i class="bi bi-file-earmark-x"></i>
            </div>

            <h4 class="fw-bold mb-2">
                No Report Cards Found
            </h4>

            <p class="text-muted mb-4">
                There are no students with entered results for the
                selected academic year, grade, section and semester.
            </p>

            <button
                type="button"
                class="btn btn-primary"
                onclick="window.close();"
            >
                <i class="bi bi-x-lg me-1"></i>
                Close
            </button>

        </div>

    </body>

    </html>

    <?php
    exit;
}

/*
|--------------------------------------------------------------------------
| Two Students Per A4 Page
|--------------------------------------------------------------------------
*/

$studentChunks = array_chunk(
    $students,
    2
);

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
        Report Cards |
        <?= e((string) $academicYear['name']) ?> |
        <?= e((string) $grade['name']) ?> |
        <?= e($sectionDisplay) ?>
    </title>

    <link
        rel="icon"
        type="image/webp"
        href="../../public/logo.webp?v=1"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
        rel="stylesheet"
    >

    <style>

        /*
        |--------------------------------------------------------------------------
        | Base
        |--------------------------------------------------------------------------
        */

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            background: #e5e7eb;
            color: #111827;
            font-family: Arial, Helvetica, sans-serif;
        }

        /*
        |--------------------------------------------------------------------------
        | Print Toolbar
        |--------------------------------------------------------------------------
        */

        .print-toolbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;

            z-index: 9999;

            min-height: 58px;

            background: #111827;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 8px 18px;

            box-shadow: 0 3px 12px rgba(0, 0, 0, .2);
        }

        .toolbar-title {
            color: #fff;
            font-size: 14px;
            font-weight: 700;
        }

        .toolbar-info {
            color: #9ca3af;
            font-size: 11px;
            margin-top: 2px;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
        }

        .toolbar-btn {
            border: 0;
            border-radius: 7px;
            padding: 9px 14px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .toolbar-print {
            background: #4f46e5;
            color: #fff;
        }

        .toolbar-close {
            background: #374151;
            color: #fff;
        }

        /*
        |--------------------------------------------------------------------------
        | A4 Page
        |--------------------------------------------------------------------------
        */

        .a4-page {
            width: 210mm;
            height: 297mm;

            margin: 75px auto 20px;

            background: #fff;

            padding: 9mm;

            position: relative;

            page-break-after: always;

            overflow: hidden;
        }

        .a4-page:last-child {
            page-break-after: auto;
        }

        /*
        |--------------------------------------------------------------------------
        | Student Card
        |--------------------------------------------------------------------------
        */

        .student-card {
            height: 136mm;

            border: 1.2px solid #334155;

            padding: 4mm;

            position: relative;

            overflow: hidden;

            background: #fff;

            /*
            |--------------------------------------------------------------------------
            | Important:
            | Flex layout allows signatures to move to the bottom.
            |--------------------------------------------------------------------------
            */

            display: flex;
            flex-direction: column;
        }

        /*
        |--------------------------------------------------------------------------
        | Inner Border
        |--------------------------------------------------------------------------
        */

        .student-card::before {
            content: "";

            position: absolute;

            inset: 2mm;

            border: 0.6px solid #cbd5e1;

            pointer-events: none;

            z-index: 0;
        }

        .student-card > * {
            position: relative;
            z-index: 1;
        }

        .student-card + .student-card {
            margin-top: 7mm;
        }

        /*
        |--------------------------------------------------------------------------
        | School Header
        |--------------------------------------------------------------------------
        */

        .school-header {
            display: flex;

            align-items: center;
            justify-content: center;

            position: relative;

            min-height: 21mm;

            background: #f8fafc;

            border: 1px solid #cbd5e1;

            border-top: 2.2px solid #4f46e5;

            border-bottom: 1px solid #cbd5e1;

            padding: 2.5mm 3mm 2mm;

            flex-shrink: 0;
        }

        .school-header::after {
            content: "";

            position: absolute;

            left: 50%;

            transform: translateX(-50%);

            bottom: 0.8mm;

            width: 35mm;

            height: 0.7mm;

            background: #4f46e5;

            border-radius: 999px;
        }

        .school-logo {
            position: absolute;

            left: 2mm;
            top: 2mm;

            width: 18mm;
            height: 18mm;

            object-fit: contain;

            padding: 1mm;

            background: #fff;

            border: 1px solid #cbd5e1;

            border-radius: 2mm;
        }

        .school-text {
            text-align: center;

            padding: 0 20mm;
        }

        .school-name {
            font-size: 16px;

            font-weight: 800;

            text-transform: uppercase;

            color: #0f172a;

            margin-bottom: 2px;

            letter-spacing: .2px;
        }

        .school-report-title {
            font-size: 13px;

            font-weight: 800;

            color: #4f46e5;

            margin-bottom: 3px;

            letter-spacing: .3px;
        }

        /*
        |--------------------------------------------------------------------------
        | Academic Information
        |--------------------------------------------------------------------------
        */

        .academic-info {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            border: 1px solid #cbd5e1;

            border-top: 0;

            background: #fff;

            flex-shrink: 0;
        }

        .academic-item {
            padding: 1.8mm 2mm;

            border-right: 1px solid #cbd5e1;
        }

        .academic-item:last-child {
            border-right: 0;
        }

        .academic-label {
            display: block;

            font-size: 7px;

            color: #64748b;

            text-transform: uppercase;

            font-weight: 700;

            margin-bottom: 1mm;
        }

        .academic-value {
            display: block;

            font-size: 9px;

            font-weight: 700;

            color: #0f172a;
        }

        /*
        |--------------------------------------------------------------------------
        | Student Information
        |--------------------------------------------------------------------------
        */

        .student-info {
            display: grid;

            grid-template-columns:
                1.5fr 1fr;

            border: 1px solid #cbd5e1;

            border-top: 0;

            background: #fff;

            flex-shrink: 0;
        }

        .student-item {
            padding: 1.8mm 2mm;

            border-right: 1px solid #cbd5e1;
        }

        .student-item:last-child {
            border-right: 0;
        }

        .student-label {
            display: block;

            font-size: 7px;

            color: #64748b;

            text-transform: uppercase;

            font-weight: 700;

            margin-bottom: 1mm;
        }

        .student-value {
            display: block;

            font-size: 9px;

            font-weight: 700;

            color: #0f172a;
        }

        /*
        |--------------------------------------------------------------------------
        | Results + Summary Layout
        |--------------------------------------------------------------------------
        */

        .results-summary-layout {
            display: grid;

            grid-template-columns:
                minmax(0, 1fr) 42mm;

            gap: 3mm;

            align-items: start;

            margin-top: 2mm;
        }

        .results-section {
            min-width: 0;
        }

        .results-title {
            margin: 0 0 1mm;

            font-size: 9.5px;

            font-weight: 800;

            color: #0f172a;

            text-transform: uppercase;

            letter-spacing: .2px;
        }

        /*
        |--------------------------------------------------------------------------
        | Results Table
        |--------------------------------------------------------------------------
        */

        .results-table {
            width: 100%;

            border-collapse: collapse;

            table-layout: fixed;
        }

        .results-table th,
        .results-table td {
            border: 1px solid #94a3b8;

            padding: 1mm 1.5mm;

            font-size: 8.7px;

            line-height: 1.1;

            vertical-align: middle;
        }

        .results-table th {
            background: #f1f5f9;

            color: #0f172a;

            font-weight: 800;

            text-align: center;
        }

        .results-table th:first-child {
            width: 9mm;
        }

        .results-table th:nth-child(2) {
            text-align: left;
        }

        .results-table th:nth-child(3),
        .results-table td:nth-child(3) {
            text-align: center;

            font-weight: 700;
        }

        .results-table th:last-child,
        .results-table td:last-child {
            width: 20mm;

            text-align: center;

            font-weight: 700;
        }

        .results-table .grade-column {
            width: 15mm;

            text-align: center;

            font-weight: 700;
        }

        .results-table .mark-column {
            width: 20mm;

            text-align: center;

            font-weight: 700;
        }

        .results-table td:first-child {
            text-align: center;

            font-weight: 700;
        }

        .results-table td:nth-child(2) {
            font-weight: 600;

            color: #1e293b;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;
        }

        /*
        |--------------------------------------------------------------------------
        | Compact Summary Card
        |--------------------------------------------------------------------------
        */

        .summary-card {
            border: 1px solid #cbd5e1;

            background: #f8fafc;

            min-height: 100%;
        }

        .summary-card-header {
            padding: 2mm 2.5mm;

            background: #eef2ff;

            border-bottom: 1px solid #cbd5e1;

            color: #3730a3;

            font-size: 8.5px;

            font-weight: 800;

            text-transform: uppercase;

            letter-spacing: .2px;
        }

        .summary-card-body {
            padding: 1.5mm;
        }

        .summary-item {
            display: grid;

            grid-template-columns:
                1fr auto;

            align-items: center;

            min-height: 7.5mm;

            padding: 1.2mm 1.5mm;

            border-bottom: 1px solid #e2e8f0;
        }

        .summary-item:last-child {
            border-bottom: 0;
        }

        .summary-item-label {
            font-size: 8px;

            font-weight: 700;

            color: #475569;
        }

        .summary-item-value {
            font-size: 10px;

            font-weight: 800;

            color: #0f172a;

            text-align: right;

            padding-left: 2mm;
        }

        .summary-item:first-child .summary-item-value {
            color: #3730a3;
        }

        .summary-item:last-child .summary-item-value {
            color: #3730a3;
        }

        /*
        |--------------------------------------------------------------------------
        | Bottom Signatures
        |--------------------------------------------------------------------------
        |
        | Desired layout:
        |
        |       Getayalew ✍              Principal ✍
        |       ───────────              ─────────────
        |          Teacher                  Director
        |
        | The role is centered UNDER the name + signature.
        |--------------------------------------------------------------------------
        */

        .signatures {
            display: grid;

            grid-template-columns: 1fr 1fr;

            column-gap: 8mm;

            /*
            |--------------------------------------------------------------------------
            | Push the whole signature area to the bottom.
            |--------------------------------------------------------------------------
            */

            margin-top: auto;

            padding: 4mm 1mm 0;

            border-top: 1px solid #cbd5e1;

            flex-shrink: 0;
        }

        .signature-box {
            min-width: 0;

            display: flex;

            flex-direction: column;

            align-items: center;
        }

        /*
        |--------------------------------------------------------------------------
        | Name + Signature On One Line
        |--------------------------------------------------------------------------
        */

        .signature-main-row {
            display: inline-flex;

            align-items: flex-end;

            justify-content: center;

            gap: 1.5mm;

            min-height: 8mm;

            /*
            |--------------------------------------------------------------------------
            | ONE underline only
            |--------------------------------------------------------------------------
            */

            border-bottom: 1px solid #475569;

            padding: 0 1mm 0.5mm;

            max-width: 64mm;

            overflow: hidden;
        }

        /*
        |--------------------------------------------------------------------------
        | Name
        |--------------------------------------------------------------------------
        */

        .signature-name {
            flex: 0 1 auto;

            min-width: 0;

            max-width: 35mm;

            font-size: 8px;

            font-weight: 700;

            color: #0f172a;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;
        }

        /*
        |--------------------------------------------------------------------------
        | Signature Image
        |--------------------------------------------------------------------------
        */

        .signature-line {
            flex: 0 0 auto;

            display: flex;

            align-items: flex-end;

            justify-content: center;

            width: 22mm;

            height: 7mm;
        }

        .signature-image {
            display: block;

            width: auto;

            max-width: 22mm;

            height: 7mm;

            max-height: 7mm;

            object-fit: contain;

            object-position: center bottom;
        }

        /*
        |--------------------------------------------------------------------------
        | Role Under Name + Signature
        |--------------------------------------------------------------------------
        */

        .signature-role {
            margin-top: 1.5mm;

            font-size: 8px;

            font-weight: 800;

            color: #334155;

            text-align: center;

            line-height: 1.1;

            width: 100%;
        }

        /*
        |--------------------------------------------------------------------------
        | Footer
        |--------------------------------------------------------------------------
        */

        .card-footer {
            display: none;
        }

        /*
        |--------------------------------------------------------------------------
        | Print
        |--------------------------------------------------------------------------
        */

        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {

            html,
            body {
                background: #fff;
            }

            .print-toolbar {
                display: none !important;
            }

            .a4-page {
                width: 210mm;

                height: 297mm;

                min-height: 297mm;

                margin: 0;

                padding: 9mm;

                page-break-after: always;
            }

            .a4-page:last-child {
                page-break-after: auto;
            }

            .student-card {
                break-inside: avoid;

                -webkit-print-color-adjust: exact;

                print-color-adjust: exact;
            }

            .school-header {
                -webkit-print-color-adjust: exact;

                print-color-adjust: exact;
            }

            .results-table th,
            .summary-card-header {
                -webkit-print-color-adjust: exact;

                print-color-adjust: exact;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Screen
        |--------------------------------------------------------------------------
        */

        @media screen and (max-width: 900px) {

            .a4-page {
                max-width: 100%;

                width: 210mm;

                overflow: hidden;
            }

            .toolbar-title {
                display: none;
            }
        }

    </style>

</head>

<body>

<!-- ================================================================
     Print Toolbar
================================================================ -->

<div class="print-toolbar">

    <div>

        <div class="toolbar-title">
            BKHS Report Cards
        </div>

        <div class="toolbar-info">

            <?= e((string) $academicYear['name']) ?> E.C

            ·

            <?= e((string) $grade['name']) ?>

            ·

            <?= e($sectionDisplay) ?>

            ·

            <?= e((string) $semester['name']) ?>

        </div>

    </div>

    <div class="toolbar-actions">

        <button
            type="button"
            class="toolbar-btn toolbar-print"
            onclick="window.print()"
        >
            <i class="bi bi-printer me-1"></i>
            Print
        </button>

        <button
            type="button"
            class="toolbar-btn toolbar-close"
            onclick="window.close()"
        >
            <i class="bi bi-x-lg me-1"></i>
            Close
        </button>

    </div>

</div>

<!-- ================================================================
     A4 Pages
================================================================ -->

<?php foreach ($studentChunks as $pageStudents): ?>

    <section class="a4-page">

        <?php foreach ($pageStudents as $student): ?>

            <?php

            $registrationId =
                (int) $student['registration_id'];

            /*
            |--------------------------------------------------------------------------
            | Calculate Total And Average
            |--------------------------------------------------------------------------
            */

            $totalMark = 0.0;

            $subjectCount = 0;

            foreach ($subjects as $subject) {

                $subjectId =
                    (int) $subject['id'];

                if (
                    isset(
                        $studentResults[$registrationId][$subjectId]
                    ) &&
                    $studentResults[$registrationId][$subjectId] !== null
                ) {

                    $totalMark +=
                        (float) $studentResults[$registrationId][$subjectId];

                    $subjectCount++;
                }
            }

            $average =
                $subjectCount > 0
                    ? $totalMark / $subjectCount
                    : 0;

            $studentRank =
                $studentRanks[$registrationId] ?? '-';

            ?>

            <div class="student-card">

                <!-- ====================================================
                     School Header
                ===================================================== -->

                <div class="school-header">

                    <img
                        src="../../public/image/logo.webp"
                        alt="BKHS Logo"
                        class="school-logo"
                    >

                    <div class="school-text">

                        <div class="school-name">
                            Bole Kale Hiwot School
                        </div>

                        <div class="school-report-title">
                            STUDENT REPORT CARD
                        </div>

                    </div>

                </div>

                <!-- ====================================================
                     Academic Information
                ===================================================== -->

                <div class="academic-info">

                    <div class="academic-item">

                        <span class="academic-label">
                            Academic Year
                        </span>

                        <span class="academic-value">
                            <?= e((string) $academicYear['name']) ?> E.C
                        </span>

                    </div>

                    <div class="academic-item">

                        <span class="academic-label">
                            Grade
                        </span>

                        <span class="academic-value">
                            <?= e((string) $grade['name']) ?>
                        </span>

                    </div>

                    <div class="academic-item">

                        <span class="academic-label">
                            Section
                        </span>

                        <span class="academic-value">
                            <?= e($sectionDisplay) ?>
                        </span>

                    </div>

                    <div class="academic-item">

                        <span class="academic-label">
                            Semester
                        </span>

                        <span class="academic-value">
                            <?= e((string) $semester['name']) ?>
                        </span>

                    </div>

                </div>

                <!-- ====================================================
                     Student Information
                ===================================================== -->

                <div class="student-info">

                    <div class="student-item">

                        <span class="student-label">
                            Student Name
                        </span>

                        <span class="student-value">
                            <?= e((string) $student['full_name']) ?>
                        </span>

                    </div>

                    <div class="student-item">

                        <span class="student-label">
                            Student Code
                        </span>

                        <span class="student-value">
                            <?= e((string) $student['student_code']) ?>
                        </span>

                    </div>

                </div>

                <!-- ====================================================
                     Academic Results + Summary
                ===================================================== -->

                <div class="results-summary-layout">

                    <!-- =================================================
                         Academic Results
                    ================================================== -->

                    <div class="results-section">

                        <div class="results-title">
                            Academic Results
                        </div>

                        <table class="results-table">

                            <thead>

                                <tr>

                                    <th>
                                        No.
                                    </th>

                                    <th>
                                        Subject
                                    </th>

                                    <?php if ($showGradeColumn): ?>

                                        <th class="grade-column">
                                            Grade
                                        </th>

                                    <?php endif; ?>

                                    <th class="mark-column">
                                        Mark
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php if (!empty($subjects)): ?>

                                    <?php
                                    $subjectNumber = 1;
                                    ?>

                                    <?php foreach ($subjects as $subject): ?>

                                        <?php

                                        $subjectId =
                                            (int) $subject['id'];

                                        $mark = null;

                                        if (
                                            isset(
                                                $studentResults[
                                                    $registrationId
                                                ][$subjectId]
                                            )
                                        ) {
                                            $mark =
                                                $studentResults[
                                                    $registrationId
                                                ][$subjectId];
                                        }

                                        /*
                                        |--------------------------------------------------------------------------
                                        | Calculate Grade Letter
                                        |--------------------------------------------------------------------------
                                        */

                                        $gradeLetter = '';

                                        if (
                                            $showGradeColumn &&
                                            $mark !== null
                                        ) {

                                            $numericMark =
                                                (float) $mark;

                                            if ($numericMark >= 90) {

                                                $gradeLetter = 'A+';

                                            } elseif ($numericMark >= 85) {

                                                $gradeLetter = 'A';

                                            } elseif ($numericMark >= 80) {

                                                $gradeLetter = 'A-';

                                            } elseif ($numericMark >= 75) {

                                                $gradeLetter = 'B+';

                                            } elseif ($numericMark >= 70) {

                                                $gradeLetter = 'B';

                                            } elseif ($numericMark >= 65) {

                                                $gradeLetter = 'B-';

                                            } elseif ($numericMark >= 60) {

                                                $gradeLetter = 'C+';

                                            } elseif ($numericMark >= 50) {

                                                $gradeLetter = 'C';

                                            } elseif ($numericMark >= 45) {

                                                $gradeLetter = 'C-';

                                            } elseif ($numericMark >= 40) {

                                                $gradeLetter = 'D';

                                            } else {

                                                $gradeLetter = 'F';
                                            }
                                        }

                                        ?>

                                        <tr>

                                            <td>
                                                <?= $subjectNumber ?>
                                            </td>

                                            <td>
                                                <?= e(
                                                    (string) $subject['subject_name']
                                                ) ?>
                                            </td>

                                            <?php if ($showGradeColumn): ?>

                                                <td class="grade-column">

                                                    <?php if ($gradeLetter !== ''): ?>

                                                        <?= e($gradeLetter) ?>

                                                    <?php else: ?>

                                                        —

                                                    <?php endif; ?>

                                                </td>

                                            <?php endif; ?>

                                            <td class="mark-column">

                                                <?php if ($mark !== null): ?>

                                                    <?= numberFormat(
                                                        (float) $mark
                                                    ) ?>

                                                <?php else: ?>

                                                    —

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                        <?php
                                        $subjectNumber++;
                                        ?>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>

                                        <td
                                            colspan="<?= $showGradeColumn ? '4' : '3' ?>"
                                            style="text-align:center;"
                                        >
                                            No subjects found.
                                        </td>

                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <!-- =================================================
                         Performance Summary
                    ================================================== -->

                    <div class="summary-card">

                        <div class="summary-card-header">
                            Performance Summary
                        </div>

                        <div class="summary-card-body">

                            <div class="summary-item">

                                <div class="summary-item-label">
                                    Total Mark
                                </div>

                                <div class="summary-item-value">
                                    <?= numberFormat($totalMark) ?>
                                </div>

                            </div>

                            <div class="summary-item">

                                <div class="summary-item-label">
                                    Average
                                </div>

                                <div class="summary-item-value">
                                    <?= numberFormat($average) ?>
                                </div>

                            </div>

                            <div class="summary-item">

                                <div class="summary-item-label">
                                    Rank
                                </div>

                                <div class="summary-item-value">
                                    <?= e((string) $studentRank) ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <!-- ====================================================
                     Bottom Signatures
                ===================================================== -->

                <div class="signatures">

                    <!-- =================================================
                         Teacher
                    ================================================== -->

                    <div class="signature-box">

                        <div class="signature-main-row">

                            <span class="signature-name">

                                <?= $teacherName !== ''
                                    ? e($teacherName)
                                    : '—'
                                ?>

                            </span>

                            <span class="signature-line">

                                <?php if ($teacherSignatureUrl): ?>

                                    <img
                                        src="<?= e($teacherSignatureUrl) ?>"
                                        alt="Teacher Signature"
                                        class="signature-image"
                                    >

                                <?php endif; ?>

                            </span>

                        </div>

                        <div class="signature-role">
                            Teacher
                        </div>

                    </div>

                    <!-- =================================================
                         Director
                    ================================================== -->

                    <div class="signature-box">

                        <div class="signature-main-row">

                            <span class="signature-name">
                                <?= e($principalName) ?>
                            </span>

                            <span class="signature-line">

                                <?php if ($principalSignatureUrl): ?>

                                    <img
                                        src="<?= e($principalSignatureUrl) ?>"
                                        alt="Director Signature"
                                        class="signature-image"
                                    >

                                <?php endif; ?>

                            </span>

                        </div>

                        <div class="signature-role">
                            Director
                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </section>

<?php endforeach; ?>

</body>

</html>