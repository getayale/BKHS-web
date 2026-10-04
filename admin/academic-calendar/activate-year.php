<?php

session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['role']) ||
    strtolower($_SESSION['role']) !== 'admin'
) {
    header('Location: ../../auth/login.php');
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/AuditLogger.php';

/*
|--------------------------------------------------------------------------
| Get Academic Year ID
|--------------------------------------------------------------------------
*/

$academicYearId = (int) ($_GET['id'] ?? 0);

if ($academicYearId <= 0) {
    $_SESSION['error_message'] = 'Invalid academic year.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Load Academic Year
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare(
    "SELECT
        id,
        name,
        status
     FROM academic_years
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    $_SESSION['error_message'] =
        'Unable to prepare the academic year request.';
    header('Location: index.php');
    exit;
}

$stmt->bind_param('i', $academicYearId);
$stmt->execute();

$result = $stmt->get_result();
$academicYear = $result->fetch_assoc();

$stmt->close();

if (!$academicYear) {
    $_SESSION['error_message'] = 'Academic year not found.';
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Prevent Activating Completed Academic Year
|--------------------------------------------------------------------------
*/

if ($academicYear['status'] === 'Completed') {
    $_SESSION['error_message'] =
        "Academic year {$academicYear['name']} is already completed and cannot be activated.";

    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Activate Academic Year
|--------------------------------------------------------------------------
*/

$conn->begin_transaction();

try {

    /*
     * First make every academic year Not Completed.
     *
     * This guarantees that only one academic year
     * can be Active.
     */
    $resetYearsStmt = $conn->prepare(
        "UPDATE academic_years
         SET status = 'Not Completed'
         WHERE status = 'Active'"
    );

    if (!$resetYearsStmt) {
        throw new Exception(
            'Unable to prepare academic year status update.'
        );
    }

    if (!$resetYearsStmt->execute()) {
        throw new Exception(
            'Unable to reset active academic years.'
        );
    }

    $resetYearsStmt->close();

    /*
     * Activate the selected academic year.
     */
    $activateYearStmt = $conn->prepare(
        "UPDATE academic_years
         SET status = 'Active'
         WHERE id = ?"
    );

    if (!$activateYearStmt) {
        throw new Exception(
            'Unable to prepare academic year activation.'
        );
    }

    $activateYearStmt->bind_param(
        'i',
        $academicYearId
    );

    if (!$activateYearStmt->execute()) {
        throw new Exception(
            'Unable to activate the academic year.'
        );
    }

    $activateYearStmt->close();

    /*
     |--------------------------------------------------------------------------
     | Reset All Semesters
     |--------------------------------------------------------------------------
     |
     | When a new academic year becomes active, all of its semesters
     | start as Not Completed.
     |
     */
    $resetSemestersStmt = $conn->prepare(
        "UPDATE semesters
         SET status = 'Not Completed'
         WHERE academic_year_id = ?"
    );

    if (!$resetSemestersStmt) {
        throw new Exception(
            'Unable to prepare semester status update.'
        );
    }

    $resetSemestersStmt->bind_param(
        'i',
        $academicYearId
    );

    if (!$resetSemestersStmt->execute()) {
        throw new Exception(
            'Unable to reset semester statuses.'
        );
    }

    $resetSemestersStmt->close();

    /*
     |--------------------------------------------------------------------------
     | Activate First Semester
     |--------------------------------------------------------------------------
     |
     | Semester order:
     |
     | 1. Mid Semester
     | 2. First Semester
     | 3. Quarter Semester
     | 4. Second Semester
     |
     | A newly activated academic year starts from Mid Semester.
     |
     */
    $activateSemesterStmt = $conn->prepare(
        "UPDATE semesters
         SET status = 'Active'
         WHERE academic_year_id = ?
           AND order_number = 1"
    );

    if (!$activateSemesterStmt) {
        throw new Exception(
            'Unable to prepare first semester activation.'
        );
    }

    $activateSemesterStmt->bind_param(
        'i',
        $academicYearId
    );

    if (!$activateSemesterStmt->execute()) {
        throw new Exception(
            'Unable to activate the first semester.'
        );
    }

    if ($activateSemesterStmt->affected_rows === 0) {
        throw new Exception(
            'The first semester was not found for this academic year.'
        );
    }

    $activateSemesterStmt->close();

    /*
     |--------------------------------------------------------------------------
     | Commit
     |--------------------------------------------------------------------------
     */

    $conn->commit();

    /*
     |--------------------------------------------------------------------------
     | Audit Log
     |--------------------------------------------------------------------------
     */

    try {
        AuditLogger::log(
            $conn,
            'ACADEMIC_YEAR_ACTIVATED',
            'Activated an academic year and reset its semesters.',
            'academic_year',
            (string) $academicYearId,
            [
                'academic_year_id' => $academicYearId,
                'name' => $academicYear['name'],
                'status' => $academicYear['status']
            ],
            [
                'academic_year_id' => $academicYearId,
                'name' => $academicYear['name'],
                'status' => 'Active',
                'active_semester' => 'Mid Semester'
            ]
        );
    } catch (Throwable $auditException) {
        error_log(
            'AuditLogger ACADEMIC_YEAR_ACTIVATED failed for academic year ID ' .
            $academicYearId .
            ': ' .
            $auditException->getMessage()
        );
    }

    $_SESSION['success_message'] =
        "Academic year {$academicYear['name']} is now active. Mid Semester is now the active semester.";

    header('Location: index.php');
    exit;

} catch (Throwable $e) {

    /*
     |--------------------------------------------------------------------------
     | Rollback
     |--------------------------------------------------------------------------
     */

    $conn->rollback();

    /*
     * Show the actual error during development.
     * Once the system is stable, this can be changed
     * to a generic error message.
     */
    $_SESSION['error_message'] =
        $e->getMessage();

    header('Location: index.php');
    exit;
}
?>