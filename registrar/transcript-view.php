<?php

declare(strict_types=1);

session_start();


/*
|--------------------------------------------------------------------------
| Registrar Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'registrar'
) {
    header('Location: ../auth/login.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Dependencies
|--------------------------------------------------------------------------
*/

require_once '../config/database.php';
require_once 'transcript-data.php';


/*
|--------------------------------------------------------------------------
| Get Student ID
|--------------------------------------------------------------------------
*/

$studentId = filter_input(
    INPUT_GET,
    'student_id',
    FILTER_VALIDATE_INT
);

if (!$studentId || $studentId <= 0) {
    header('Location: Transcript.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Complete Student Transcript
|--------------------------------------------------------------------------
*/

$transcript = getStudentTranscript(
    $conn,
    (int) $studentId
);

if (!$transcript) {
    header('Location: Transcript.php?error=student_not_found');
    exit;
}

$student = $transcript['student'];
$academicYears = $transcript['academic_years'];


/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function formatMark(?float $mark): string
{
    if ($mark === null) {
        return '—';
    }

    return number_format($mark, 2);
}


function formatAverage(?float $average): string
{
    if ($average === null) {
        return '—';
    }

    return number_format($average, 2) . '%';
}


/*
|--------------------------------------------------------------------------
| School Information
|--------------------------------------------------------------------------
*/

$schoolName = 'BOLE KALE HIWOT SCHOOL';
$schoolLogo = '../public/logo.webp';


/*
|--------------------------------------------------------------------------
| Get Registrar Information
|--------------------------------------------------------------------------
*/

$registrar = [
    'full_name' => 'Registrar',
    'signature_path' => null,
];

$registrarUserId = (int) $_SESSION['user_id'];

$registrarStmt = $conn->prepare(
    "
    SELECT
        id,
        full_name,
        signature_path
    FROM users
    WHERE id = ?
      AND LOWER(role) = 'registrar'
      AND is_deleted = 0
    LIMIT 1
    "
);

if ($registrarStmt) {

    $registrarStmt->bind_param(
        'i',
        $registrarUserId
    );

    $registrarStmt->execute();

    $registrarResult = $registrarStmt->get_result();

    if ($registrarRow = $registrarResult->fetch_assoc()) {

        $registrar['full_name'] =
            (string) ($registrarRow['full_name'] ?? 'Registrar');

        $registrar['signature_path'] =
            !empty($registrarRow['signature_path'])
                ? (string) $registrarRow['signature_path']
                : null;
    }

    $registrarStmt->close();
}


/*
|--------------------------------------------------------------------------
| Get Principal Information
|--------------------------------------------------------------------------
*/

$principal = [
    'full_name' => 'Principal',
    'signature_path' => null,
];

$principalStmt = $conn->prepare(
    "
    SELECT
        id,
        full_name,
        signature_path
    FROM users
    WHERE LOWER(role) = 'principal'
      AND is_deleted = 0
    ORDER BY id ASC
    LIMIT 1
    "
);

if ($principalStmt) {

    $principalStmt->execute();

    $principalResult = $principalStmt->get_result();

    if ($principalRow = $principalResult->fetch_assoc()) {

        $principal['full_name'] =
            (string) ($principalRow['full_name'] ?? 'Principal');

        $principal['signature_path'] =
            !empty($principalRow['signature_path'])
                ? (string) $principalRow['signature_path']
                : null;
    }

    $principalStmt->close();
}


/*
|--------------------------------------------------------------------------
| Signature URL Helper
|--------------------------------------------------------------------------
*/

function signatureUrl(?string $signaturePath): ?string
{
    if (
        $signaturePath === null ||
        trim($signaturePath) === ''
    ) {
        return null;
    }

    $path = trim($signaturePath);

    /*
    | Normalize Windows path separators.
    */
    $path = str_replace('\\', '/', $path);

    /*
    | Remove null bytes.
    */
    $path = str_replace("\0", '', $path);

    /*
    | Remove leading slash.
    */
    $path = ltrim($path, '/');

    /*
    | Prevent directory traversal.
    */
    if (
        str_contains($path, '../') ||
        str_contains($path, '..\\') ||
        str_contains($path, '..')
    ) {
        return null;
    }

    /*
    | Encode each path segment while preserving /.
    */
    $segments = explode('/', $path);

    $encodedSegments = [];

    foreach ($segments as $segment) {

        if ($segment === '') {
            continue;
        }

        $encodedSegments[] = rawurlencode($segment);
    }

    if (empty($encodedSegments)) {
        return null;
    }

    return '../' . implode('/', $encodedSegments);
}


/*
|--------------------------------------------------------------------------
| Signature File Existence Helper
|--------------------------------------------------------------------------
*/

function signatureFileExists(?string $signaturePath): bool
{
    if (
        $signaturePath === null ||
        trim($signaturePath) === ''
    ) {
        return false;
    }

    $path = trim($signaturePath);

    $path = str_replace(
        ['\\', "\0"],
        ['/', ''],
        $path
    );

    $path = ltrim($path, '/');

    if (
        $path === '' ||
        str_contains($path, '..')
    ) {
        return false;
    }

    $projectRoot = realpath(
        __DIR__ . '/../'
    );

    if ($projectRoot === false) {
        return false;
    }

    $fullPath = realpath(
        $projectRoot . DIRECTORY_SEPARATOR . $path
    );

    if (
        $fullPath === false ||
        !is_file($fullPath)
    ) {
        return false;
    }

    /*
    | Make sure the file remains inside the BKHS project.
    */
    $projectRootNormalized = rtrim(
        str_replace('\\', '/', $projectRoot),
        '/'
    );

    $fullPathNormalized = str_replace(
        '\\',
        '/',
        $fullPath
    );

    return str_starts_with(
        $fullPathNormalized,
        $projectRootNormalized . '/'
    );
}


/*
|--------------------------------------------------------------------------
| Prepare Signature Display
|--------------------------------------------------------------------------
*/

$registrarSignatureUrl = null;

if (
    !empty($registrar['signature_path']) &&
    signatureFileExists($registrar['signature_path'])
) {
    $registrarSignatureUrl =
        signatureUrl($registrar['signature_path']);
}


$principalSignatureUrl = null;

if (
    !empty($principal['signature_path']) &&
    signatureFileExists($principal['signature_path'])
) {
    $principalSignatureUrl =
        signatureUrl($principal['signature_path']);
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
    Academic Transcript -
    <?= e($student['full_name']) ?>
</title>


<!-- Favicon -->

<link
    rel="icon"
    type="image/webp"
    href="../public/logo.webp?v=1"
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


<!-- Transcript Styles -->

<link
    rel="stylesheet"
    href="transcript-style.php"
>


<!-- =====================================================
     SIGNATURE LAYOUT
====================================================== -->

<style>

/*
|--------------------------------------------------------------------------
| Signature Section
|--------------------------------------------------------------------------
|
| Registrar = LEFT
| Principal = RIGHT
|
| Name + signature are centered together.
| Short underline is below them.
| Role label is below the underline.
|
*/

.signature-section {
    width: 100% !important;

    display: grid !important;

    grid-template-columns: 1fr 1fr !important;

    column-gap: 55px !important;

    align-items: start !important;

    margin-top: 28px !important;

    padding: 0 12px !important;

    box-sizing: border-box !important;
}


/*
|--------------------------------------------------------------------------
| Signature Box
|--------------------------------------------------------------------------
*/

.signature-box {
    width: 100% !important;

    min-width: 0 !important;

    overflow: visible !important;

    box-sizing: border-box !important;
}


/*
|--------------------------------------------------------------------------
| Name + Signature Row
|--------------------------------------------------------------------------
|
| Name and signature are centered together.
|
*/

.signature-content-row {
    width: 100% !important;

    min-height: 42px !important;

    display: flex !important;

    align-items: flex-end !important;

    justify-content: center !important;

    gap: 5px !important;

    white-space: nowrap !important;

    box-sizing: border-box !important;
}


/*
|--------------------------------------------------------------------------
| Person Name
|--------------------------------------------------------------------------
*/

.signature-person-name {
    flex: 0 1 auto !important;

    min-width: 0 !important;

    max-width: 150px !important;

    font-size: 11px !important;

    font-weight: 600 !important;

    line-height: 18px !important;

    color: #222 !important;

    white-space: nowrap !important;

    overflow: hidden !important;

    text-overflow: ellipsis !important;
}


/*
|--------------------------------------------------------------------------
| Signature Image Wrapper
|--------------------------------------------------------------------------
*/

.signature-image-wrapper {
    flex: 0 0 75px !important;

    width: 75px !important;

    height: 34px !important;

    display: flex !important;

    align-items: flex-end !important;

    justify-content: flex-start !important;

    overflow: hidden !important;

    margin-left: 2px !important;

    box-sizing: border-box !important;
}


/*
|--------------------------------------------------------------------------
| Actual Signature Image
|--------------------------------------------------------------------------
*/

.signature-image {
    display: block !important;

    width: auto !important;

    height: auto !important;

    max-width: 70px !important;

    max-height: 30px !important;

    min-width: 0 !important;

    min-height: 0 !important;

    object-fit: contain !important;

    margin: 0 !important;
}


/*
|--------------------------------------------------------------------------
| Empty Signature Space
|--------------------------------------------------------------------------
*/

.signature-empty-space {
    flex: 0 0 75px !important;

    width: 75px !important;

    height: 34px !important;
}


/*
|--------------------------------------------------------------------------
| SMALL UNDERLINE
|--------------------------------------------------------------------------
|
| Short centered underline below Name + Signature.
|
*/

.signature-underline {
    width: 180px !important;

    max-width: 100% !important;

    height: 1px !important;

    border-bottom: 1px solid #222 !important;

    margin: 2px auto 0 !important;

    box-sizing: border-box !important;
}


/*
|--------------------------------------------------------------------------
| Role Label
|--------------------------------------------------------------------------
|
| Registrar / Principal appear BELOW the underline.
| No colon.
|
*/

.signature-role-label {
    display: block !important;

    width: 180px !important;

    max-width: 100% !important;

    margin: 5px auto 0 !important;

    text-align: center !important;

    font-size: 11px !important;

    font-weight: 700 !important;

    line-height: 16px !important;

    color: #222 !important;

    white-space: nowrap !important;
}


/*
|--------------------------------------------------------------------------
| Print
|--------------------------------------------------------------------------
*/

@media print {

    .transcript-actions {
        display: none !important;
    }


    .signature-section {
        display: grid !important;

        grid-template-columns: 1fr 1fr !important;

        column-gap: 55px !important;

        align-items: start !important;

        width: 100% !important;

        margin-top: 28px !important;

        padding-left: 12px !important;

        padding-right: 12px !important;

        box-sizing: border-box !important;

        break-inside: avoid !important;

        page-break-inside: avoid !important;
    }


    .signature-box {
        width: 100% !important;

        min-width: 0 !important;

        overflow: visible !important;

        break-inside: avoid !important;

        page-break-inside: avoid !important;
    }


    .signature-content-row {
        width: 100% !important;

        min-height: 42px !important;

        display: flex !important;

        align-items: flex-end !important;

        justify-content: center !important;

        gap: 5px !important;

        white-space: nowrap !important;
    }


    .signature-person-name {
        font-size: 10px !important;

        max-width: 150px !important;
    }


    .signature-image-wrapper {
        flex: 0 0 75px !important;

        width: 75px !important;

        height: 34px !important;

        align-items: flex-end !important;

        justify-content: flex-start !important;

        overflow: hidden !important;

        margin-left: 2px !important;
    }


    .signature-image {
        max-width: 70px !important;

        max-height: 30px !important;

        width: auto !important;

        height: auto !important;

        object-fit: contain !important;
    }


    .signature-empty-space {
        flex: 0 0 75px !important;

        width: 75px !important;

        height: 34px !important;
    }


    /*
    | Small centered underline in printed transcript
    */

    .signature-underline {
        width: 180px !important;

        max-width: 100% !important;

        height: 1px !important;

        border-bottom: 1px solid #222 !important;

        margin: 2px auto 0 !important;
    }


    .signature-role-label {
        display: block !important;

        width: 180px !important;

        max-width: 100% !important;

        margin: 5px auto 0 !important;

        text-align: center !important;

        font-size: 10px !important;

        font-weight: 700 !important;

        line-height: 16px !important;
    }

}


/*
|--------------------------------------------------------------------------
| Small Screen Preview
|--------------------------------------------------------------------------
*/

@media screen and (max-width: 700px) {

    .signature-section {
        grid-template-columns: 1fr 1fr !important;

        column-gap: 20px !important;

        padding: 0 8px !important;
    }


    .signature-content-row {
        gap: 4px !important;

        min-height: 38px !important;

        justify-content: center !important;
    }


    .signature-person-name {
        font-size: 9px !important;

        max-width: 110px !important;
    }


    .signature-image-wrapper {
        flex: 0 0 60px !important;

        width: 60px !important;

        height: 30px !important;

        margin-left: 1px !important;
    }


    .signature-image {
        max-width: 56px !important;

        max-height: 27px !important;
    }


    .signature-empty-space {
        flex: 0 0 60px !important;

        width: 60px !important;

        height: 30px !important;
    }


    .signature-underline {
        width: 140px !important;

        max-width: 100% !important;

        margin: 2px auto 0 !important;
    }


    .signature-role-label {
        width: 140px !important;

        max-width: 100% !important;

        font-size: 9px !important;

        margin: 4px auto 0 !important;

        text-align: center !important;
    }

}

</style>

</head>


<body>


<!-- =========================================================
     ACTION BAR
========================================================== -->

<div class="transcript-actions">

    <a
        href="Transcript.php"
        class="btn btn-light"
    >

        <i class="bi bi-arrow-left"></i>

        Back to Search

    </a>


    <button
        type="button"
        class="btn btn-primary"
        onclick="window.print()"
    >

        <i class="bi bi-printer"></i>

        Print Transcript

    </button>

</div>


<!-- =========================================================
     TRANSCRIPT DOCUMENTS
========================================================== -->

<div class="transcript-container">

<?php if (empty($academicYears)): ?>


    <!-- =====================================================
         NO ACADEMIC RECORD
    ====================================================== -->

    <div class="transcript-page empty-transcript">

        <div class="empty-transcript-icon">

            <i class="bi bi-file-earmark-x"></i>

        </div>


        <h2>
            No Academic Records Found
        </h2>


        <p>
            No academic registration or result records were found
            for this student.
        </p>


        <div class="student-reference">

            <?= e($student['full_name']) ?>

            <span>
                <?= e($student['student_code']) ?>
            </span>

        </div>

    </div>


<?php else: ?>


    <?php foreach ($academicYears as $yearIndex => $year): ?>


        <!-- =================================================
             ONE ACADEMIC YEAR = ONE A4 PAGE
        ================================================== -->

        <section class="transcript-page">


            <!-- =================================================
                 SCHOOL HEADER
            ================================================== -->

            <header class="transcript-header">


                <!-- School Logo -->

                <div class="logo-container">

                    <?php if (
                        file_exists(
                            __DIR__ . '/../public/logo.webp'
                        )
                    ): ?>

                        <img
                            src="<?= e($schoolLogo) ?>"
                            alt="<?= e($schoolName) ?> Logo"
                            class="school-logo"
                        >

                    <?php else: ?>

                        <div class="logo-placeholder">

                            <i class="bi bi-mortarboard-fill"></i>

                        </div>

                    <?php endif; ?>

                </div>


                <!-- School Information -->

                <div class="school-header-text">

                    <h1>
                        <?= e($schoolName) ?>
                    </h1>


                    <div class="document-title">
                        ACADEMIC TRANSCRIPT
                    </div>


                    <div class="document-subtitle">
                        Official Academic Record
                    </div>

                </div>


                <div class="header-spacer"></div>

            </header>


            <!-- =================================================
                 ACADEMIC YEAR / GRADE / SECTION
            ================================================== -->

            <div class="academic-year-banner">


                <!-- Academic Year -->

                <div>

                    <span class="banner-label">
                        ACADEMIC YEAR
                    </span>

                    <strong>
                        <?= e($year['academic_year_name']) ?>
                    </strong>

                </div>


                <!-- Grade -->

                <div class="grade-display">

                    <span class="banner-label">
                        GRADE
                    </span>

                    <strong>
                        <?= e($year['grade_name']) ?>
                    </strong>

                </div>


                <!-- Section -->

                <div class="section-display">

                    <span class="banner-label">
                        SECTION
                    </span>

                    <strong>
                        <?= e($year['section_code']) ?>
                    </strong>

                </div>

            </div>


            <!-- =================================================
                 STUDENT INFORMATION
            ================================================== -->

            <div class="student-information">


                <!-- Student Name -->

                <div class="student-info-item">

                    <span class="info-label">
                        Student Name
                    </span>

                    <span class="info-value">
                        <?= e($student['full_name']) ?>
                    </span>

                </div>


                <!-- Student Code -->

                <div class="student-info-item">

                    <span class="info-label">
                        Student Code
                    </span>

                    <span class="info-value">
                        <?= e($student['student_code']) ?>
                    </span>

                </div>


            </div>


            <!-- =================================================
                 CERTIFICATION STATEMENT
            ================================================== -->

            <div class="certification-statement">

                <i class="bi bi-patch-check-fill"></i>

                <p>

                    This is to certify that

                    <strong>
                        <?= e($student['full_name']) ?>
                    </strong>

                    is a student of

                    <strong>
                        <?= e($schoolName) ?>
                    </strong>

                    and has the following academic record for the

                    <strong>
                        <?= e($year['academic_year_name']) ?>
                    </strong>

                    academic year.

                </p>

            </div>


            <!-- =================================================
                 ACADEMIC RECORD
            ================================================== -->

            <div class="record-section">


                <!-- Section Heading -->

                <div class="record-section-heading">

                    <div class="heading-icon">

                        <i class="bi bi-journal-text"></i>

                    </div>


                    <div>

                        <h2>
                            Academic Record
                        </h2>

                        <span>
                            First Semester and Second Semester
                        </span>

                    </div>

                </div>


                <?php if (empty($year['subjects'])): ?>


                    <!-- No Results -->

                    <div class="no-results">

                        <i class="bi bi-info-circle"></i>

                        No results have been recorded for this
                        academic year.

                    </div>


                <?php else: ?>


                    <!-- Results Table -->

                    <div class="table-wrapper">

                        <table class="transcript-table">


                            <thead>

                                <tr>

                                    <th class="number-column">
                                        No.
                                    </th>


                                    <th class="subject-column">
                                        Subject
                                    </th>


                                    <th>

                                        First Semester

                                        <small>
                                            / 100
                                        </small>

                                    </th>


                                    <th>

                                        Second Semester

                                        <small>
                                            / 100
                                        </small>

                                    </th>


                                    <th>

                                        Annual Average

                                        <small>
                                            / 100
                                        </small>

                                    </th>

                                </tr>

                            </thead>


                            <tbody>


                            <?php foreach (
                                $year['subjects']
                                as $subjectIndex => $subject
                            ): ?>


                                <tr>


                                    <!-- Number -->

                                    <td class="number-cell">

                                        <?= $subjectIndex + 1 ?>

                                    </td>


                                    <!-- Subject -->

                                    <td class="subject-cell">

                                        <?= e(
                                            $subject['subject_name']
                                        ) ?>

                                    </td>


                                    <!-- First Semester -->

                                    <td class="mark-cell">

                                        <?= formatMark(
                                            $subject['first_semester']
                                        ) ?>

                                    </td>


                                    <!-- Second Semester -->

                                    <td class="mark-cell">

                                        <?= formatMark(
                                            $subject['second_semester']
                                        ) ?>

                                    </td>


                                    <!-- Annual Average -->

                                    <td class="mark-cell annual-mark">

                                        <?= formatMark(
                                            $subject['annual_average']
                                        ) ?>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                            </tbody>

                        </table>

                    </div>


                <?php endif; ?>


            </div>


            <!-- =================================================
                 ANNUAL SUMMARY
            ================================================== -->

            <div class="summary-section">


                <div class="summary-title">

                    <i class="bi bi-bar-chart-fill"></i>

                    Annual Summary

                </div>


                <div class="summary-grid">


                    <!-- First Semester -->

                    <div class="summary-card">

                        <span>
                            First Semester Average
                        </span>

                        <strong>

                            <?= formatAverage(
                                $year['first_semester_average']
                            ) ?>

                        </strong>

                    </div>


                    <!-- Second Semester -->

                    <div class="summary-card">

                        <span>
                            Second Semester Average
                        </span>

                        <strong>

                            <?= formatAverage(
                                $year['second_semester_average']
                            ) ?>

                        </strong>

                    </div>


                    <!-- Annual Average -->

                    <div class="summary-card summary-card-primary">

                        <span>
                            Annual Average
                        </span>

                        <strong>

                            <?= formatAverage(
                                $year['annual_average']
                            ) ?>

                        </strong>

                    </div>


                </div>

            </div>


            <!-- =================================================
                 SIGNATURES
                 Registrar LEFT / Principal RIGHT
            ================================================== -->

            <div class="signature-section">


                <!-- =================================================
                     REGISTRAR - LEFT
                ================================================== -->

                <div class="signature-box">


                    <!-- NAME + SIGNATURE -->

                    <div class="signature-content-row">

                        <span class="signature-person-name">
                            <?= e($registrar['full_name']) ?>
                        </span>


                        <?php if ($registrarSignatureUrl !== null): ?>

                            <div class="signature-image-wrapper">

                                <img
                                    src="<?= e($registrarSignatureUrl) ?>"
                                    alt="Registrar Signature"
                                    class="signature-image"
                                >

                            </div>

                        <?php else: ?>

                            <div class="signature-empty-space"></div>

                        <?php endif; ?>


                    </div>


                    <!-- SMALL UNDERLINE -->

                    <div class="signature-underline"></div>


                    <!-- LABEL BELOW UNDERLINE -->

                    <span class="signature-role-label">
                        Registrar
                    </span>


                </div>


                <!-- =================================================
                     PRINCIPAL - RIGHT
                ================================================== -->

                <div class="signature-box">


                    <!-- NAME + SIGNATURE -->

                    <div class="signature-content-row">

                        <span class="signature-person-name">
                            <?= e($principal['full_name']) ?>
                        </span>


                        <?php if ($principalSignatureUrl !== null): ?>

                            <div class="signature-image-wrapper">

                                <img
                                    src="<?= e($principalSignatureUrl) ?>"
                                    alt="Principal Signature"
                                    class="signature-image"
                                >

                            </div>

                        <?php else: ?>

                            <div class="signature-empty-space"></div>

                        <?php endif; ?>


                    </div>


                    <!-- SMALL UNDERLINE -->

                    <div class="signature-underline"></div>


                    <!-- LABEL BELOW UNDERLINE -->

                    <span class="signature-role-label">
                        Principal
                    </span>


                </div>


            </div>


            <!-- =================================================
                 PAGE FOOTER
            ================================================== -->

            <footer class="transcript-footer">


                <span>
                    <?= e($schoolName) ?>
                </span>


                <span>
                    Academic Transcript
                </span>


            </footer>


        </section>


    <?php endforeach; ?>


<?php endif; ?>

</div>


<!-- =========================================================
     PRINT SCRIPT
========================================================== -->

<script>

window.addEventListener(
    'beforeprint',
    function () {

        document.body.classList.add('printing');

    }
);


window.addEventListener(
    'afterprint',
    function () {

        document.body.classList.remove('printing');

    }
);

</script>


</body>

</html>