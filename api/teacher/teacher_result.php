<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
*/

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $origin !== '' &&
    preg_match(
        '/^https?:\/\/localhost(?::[0-9]+)?$/',
        $origin
    )
) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header(
    'Access-Control-Allow-Headers: Content-Type, Authorization'
);

header(
    'Access-Control-Allow-Methods: GET, POST, OPTIONS'
);

header('Access-Control-Max-Age: 86400');

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
) {
    http_response_code(204);
    exit;
}

/*
|--------------------------------------------------------------------------
| Dependencies
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../../config/database.php';

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function jsonResponse(
    bool $success,
    string $message = '',
    array $data = [],
    int $statusCode = 200
): never {
    http_response_code($statusCode);

    $response = [
        'success' => $success,
    ];

    if ($message !== '') {
        $response['message'] = $message;
    }

    foreach ($data as $key => $value) {
        $response[$key] = $value;
    }

    echo json_encode(
        $response,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| BEARER TOKEN
|--------------------------------------------------------------------------
*/

function getBearerToken(): string
{
    $authorization = '';

    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization = trim(
            (string) $_SERVER['HTTP_AUTHORIZATION']
        );
    } elseif (
        isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    ) {
        $authorization = trim(
            (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        );
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();

        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                $authorization = trim((string) $value);
                break;
            }
        }
    }

    if (
        $authorization === '' ||
        !preg_match(
            '/^Bearer\s+(.+)$/i',
            $authorization,
            $matches
        )
    ) {
        return '';
    }

    return trim((string) $matches[1]);
}

/*
|--------------------------------------------------------------------------
| INITIALS
|--------------------------------------------------------------------------
*/

function getInitials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'T';
    }

    $parts = preg_split('/\s+/', $name);

    if (!$parts) {
        return strtoupper(
            substr($name, 0, 1)
        );
    }

    $initials = '';

    foreach (
        array_slice($parts, 0, 2) as $part
    ) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper(
            substr($part, 0, 1)
        );
    }

    return $initials !== ''
        ? $initials
        : 'T';
}

/*
|--------------------------------------------------------------------------
| REQUEST METHOD
|--------------------------------------------------------------------------
*/

$requestMethod = strtoupper(
    (string) (
        $_SERVER['REQUEST_METHOD'] ?? ''
    )
);

if (
    $requestMethod !== 'GET' &&
    $requestMethod !== 'POST'
) {
    jsonResponse(
        false,
        'Only GET and POST requests are allowed.',
        [],
        405
    );
}

/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

$token = getBearerToken();

if ($token === '') {
    jsonResponse(
        false,
        'Authorization token is required.',
        [],
        401
    );
}

$tokenHash = hash(
    'sha256',
    $token
);

try {
    $tokenStmt = $conn->prepare(
        "
        SELECT
            at.user_id,
            at.expires_at,
            u.full_name,
            u.email,
            u.phone,
            u.role,
            u.is_deleted
        FROM api_tokens at
        INNER JOIN users u
            ON u.id = at.user_id
        WHERE at.token_hash = ?
        LIMIT 1
        "
    );

    if (!$tokenStmt) {
        throw new RuntimeException(
            'Unable to prepare authentication query.'
        );
    }

    $tokenStmt->bind_param(
        's',
        $tokenHash
    );

    $tokenStmt->execute();

    $tokenResult = $tokenStmt->get_result();

    $tokenUser = $tokenResult->fetch_assoc();

    $tokenStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS teacher result authentication error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to authenticate the request.',
        [],
        500
    );
}

if (!$tokenUser) {
    jsonResponse(
        false,
        'Invalid authorization token.',
        [],
        401
    );
}

if (
    !empty($tokenUser['expires_at']) &&
    strtotime(
        (string) $tokenUser['expires_at']
    ) <= time()
) {
    jsonResponse(
        false,
        'Authorization token has expired.',
        [],
        401
    );
}

if (
    (int) $tokenUser['is_deleted'] !== 0
) {
    jsonResponse(
        false,
        'User account is unavailable.',
        [],
        403
    );
}

if (
    strtolower(
        trim(
            (string) $tokenUser['role']
        )
    ) !== 'teacher'
) {
    jsonResponse(
        false,
        'Teacher access is required.',
        [],
        403
    );
}

$teacherUserId = (int) $tokenUser['user_id'];

/*
|--------------------------------------------------------------------------
| TEACHER
|--------------------------------------------------------------------------
*/

try {
    $teacherStmt = $conn->prepare(
        "
        SELECT
            u.id AS user_id,
            u.full_name,
            u.email,
            u.phone,
            t.photo_path
        FROM users u
        LEFT JOIN teachers t
            ON t.user_id = u.id
        WHERE u.id = ?
          AND LOWER(u.role) = 'teacher'
          AND u.is_deleted = 0
        LIMIT 1
        "
    );

    if (!$teacherStmt) {
        throw new RuntimeException(
            'Unable to prepare teacher query.'
        );
    }

    $teacherStmt->bind_param(
        'i',
        $teacherUserId
    );

    $teacherStmt->execute();

    $teacherResult = $teacherStmt->get_result();

    $teacher = $teacherResult->fetch_assoc();

    $teacherStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS teacher result teacher query error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to load teacher information.',
        [],
        500
    );
}

if (!$teacher) {
    jsonResponse(
        false,
        'Teacher account was not found.',
        [],
        404
    );
}

$teacherPhoto = '';

if (
    isset($teacher['photo_path']) &&
    trim((string) $teacher['photo_path']) !== ''
) {
    $teacherPhoto = trim(
        (string) $teacher['photo_path']
    );
}

/*
|--------------------------------------------------------------------------
| ACTIVE ACADEMIC YEAR
|--------------------------------------------------------------------------
*/

try {
    $academicYearStmt = $conn->prepare(
        "
        SELECT
            id,
            name,
            status,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day
        FROM academic_years
        WHERE status = 'Active'
        ORDER BY id DESC
        LIMIT 1
        "
    );

    if (!$academicYearStmt) {
        throw new RuntimeException(
            'Unable to prepare academic year query.'
        );
    }

    $academicYearStmt->execute();

    $academicYearResult =
        $academicYearStmt->get_result();

    $academicYear =
        $academicYearResult->fetch_assoc();

    $academicYearStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS teacher result academic year error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to load the active academic year.',
        [],
        500
    );
}

if (!$academicYear) {
    jsonResponse(
        false,
        'No active academic year was found.',
        [],
        404
    );
}

$academicYearId =
    (int) $academicYear['id'];

$academicYearName =
    (string) $academicYear['name'];

/*
|--------------------------------------------------------------------------
| POST - SAVE / UPDATE RESULTS
|--------------------------------------------------------------------------
*/

if ($requestMethod === 'POST') {
    $rawBody = file_get_contents(
        'php://input'
    );

    $input = json_decode(
        $rawBody ?: '',
        true
    );

    if (!is_array($input)) {
        jsonResponse(
            false,
            'Invalid JSON request body.',
            [],
            400
        );
    }

    $semesterId = isset($input['semester_id'])
        ? (int) $input['semester_id']
        : 0;

    $grade = isset($input['grade'])
        ? (int) $input['grade']
        : 0;

    $section = isset($input['section'])
        ? strtoupper(
            trim(
                (string) $input['section']
            )
        )
        : '';

    $subjectId = isset($input['subject_id'])
        ? (int) $input['subject_id']
        : 0;

    $marks = $input['marks'] ?? null;

    if ($semesterId <= 0) {
        jsonResponse(
            false,
            'Semester is required.',
            [],
            400
        );
    }

    if ($grade <= 0) {
        jsonResponse(
            false,
            'Grade is required.',
            [],
            400
        );
    }

    if ($section === '') {
        jsonResponse(
            false,
            'Section is required.',
            [],
            400
        );
    }

    if ($subjectId <= 0) {
        jsonResponse(
            false,
            'Subject is required.',
            [],
            400
        );
    }

    if (!is_array($marks)) {
        jsonResponse(
            false,
            'Marks must be provided as an object.',
            [],
            400
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SEMESTER
    |--------------------------------------------------------------------------
    */

    try {
        $semesterStmt = $conn->prepare(
            "
            SELECT
                id,
                name,
                max_mark,
                status,
                academic_year_id
            FROM semesters
            WHERE id = ?
              AND academic_year_id = ?
            LIMIT 1
            "
        );

        if (!$semesterStmt) {
            throw new RuntimeException(
                'Unable to prepare semester query.'
            );
        }

        $semesterStmt->bind_param(
            'ii',
            $semesterId,
            $academicYearId
        );

        $semesterStmt->execute();

        $semesterResult =
            $semesterStmt->get_result();

        $semester =
            $semesterResult->fetch_assoc();

        $semesterStmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teacher result semester error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to load the selected semester.',
            [],
            500
        );
    }

    if (!$semester) {
        jsonResponse(
            false,
            'Selected semester was not found.',
            [],
            404
        );
    }

    if (
        strtolower(
            trim(
                (string) $semester['status']
            )
        ) !== 'active'
    ) {
        jsonResponse(
            false,
            'Results can only be added or edited while the semester is Active.',
            [],
            403
        );
    }

    $maxMark =
        (float) $semester['max_mark'];

    /*
    |--------------------------------------------------------------------------
    | TEACHER ASSIGNMENT
    |--------------------------------------------------------------------------
    */

    try {
        $assignmentStmt = $conn->prepare(
            "
            SELECT
                id,
                grade,
                section,
                grade_subject_id
            FROM subject_teacher_assignments
            WHERE teacher_user_id = ?
              AND academic_year = ?
              AND grade = ?
              AND section = ?
              AND grade_subject_id = ?
              AND is_active = 1
            LIMIT 1
            "
        );

        if (!$assignmentStmt) {
            throw new RuntimeException(
                'Unable to prepare teacher assignment query.'
            );
        }

        $assignmentStmt->bind_param(
            'isisi',
            $teacherUserId,
            $academicYearName,
            $grade,
            $section,
            $subjectId
        );

        $assignmentStmt->execute();

        $assignmentResult =
            $assignmentStmt->get_result();

        $assignment =
            $assignmentResult->fetch_assoc();

        $assignmentStmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teacher result assignment error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to verify the teacher assignment.',
            [],
            500
        );
    }

    if (!$assignment) {
        jsonResponse(
            false,
            'The selected subject and class are not assigned to this teacher.',
            [],
            403
        );
    }

    /*
    |--------------------------------------------------------------------------
    | STUDENTS IN SELECTED CLASS
    |--------------------------------------------------------------------------
    */

    try {
        $studentStmt = $conn->prepare(
            "
            SELECT
                sr.id AS registration_id
            FROM student_registrations sr
            INNER JOIN grades g
                ON g.id = sr.grade_id
            INNER JOIN sections sec
                ON sec.id = sr.section_id
            INNER JOIN students s
                ON s.id = sr.student_id
            WHERE sr.academic_year_id = ?
              AND g.grade_number = ?
              AND sec.code = ?
            "
        );

        if (!$studentStmt) {
            throw new RuntimeException(
                'Unable to prepare student query.'
            );
        }

        $studentStmt->bind_param(
            'iis',
            $academicYearId,
            $grade,
            $section
        );

        $studentStmt->execute();

        $studentResult =
            $studentStmt->get_result();

        $registrationIds = [];

        while (
            $student =
            $studentResult->fetch_assoc()
        ) {
            $registrationIds[] =
                (int) $student['registration_id'];
        }

        $studentStmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teacher result students error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to load students in the selected class.',
            [],
            500
        );
    }

    if (!$registrationIds) {
        jsonResponse(
            false,
            'No students are registered in the selected class.',
            [],
            404
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SAVE RESULTS
    |--------------------------------------------------------------------------
    */

    $savedCount = 0;
    $skippedCount = 0;
    $errors = [];

    $conn->begin_transaction();

    try {
        $upsertStmt = $conn->prepare(
            "
            INSERT INTO results (
                student_registration_id,
                grade_subject_id,
                semester_id,
                mark
            )
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                mark = VALUES(mark),
                updated_at = CURRENT_TIMESTAMP
            "
        );

        if (!$upsertStmt) {
            throw new RuntimeException(
                'Unable to prepare result save query.'
            );
        }

        foreach (
            $marks as $registrationIdKey => $markValue
        ) {
            $registrationIdString =
                trim(
                    (string) $registrationIdKey
                );

            if (
                $registrationIdString === '' ||
                !ctype_digit($registrationIdString)
            ) {
                $errors[] = [
                    'registration_id' => 0,
                    'message' =>
                        'Invalid student registration ID.',
                ];

                continue;
            }

            $registrationId =
                (int) $registrationIdString;

            if ($registrationId <= 0) {
                $errors[] = [
                    'registration_id' =>
                        $registrationId,
                    'message' =>
                        'Invalid student registration ID.',
                ];

                continue;
            }

            if (
                !in_array(
                    $registrationId,
                    $registrationIds,
                    true
                )
            ) {
                $errors[] = [
                    'registration_id' =>
                        $registrationId,
                    'message' =>
                        'Student does not belong to the selected class.',
                ];

                continue;
            }

            if (
                $markValue === null ||
                trim((string) $markValue) === ''
            ) {
                $skippedCount++;
                continue;
            }

            if (!is_numeric($markValue)) {
                $errors[] = [
                    'registration_id' =>
                        $registrationId,
                    'message' =>
                        'Mark must be numeric.',
                ];

                continue;
            }

            $mark = (float) $markValue;

            if ($mark < 0) {
                $errors[] = [
                    'registration_id' =>
                        $registrationId,
                    'message' =>
                        'Mark cannot be less than 0.',
                ];

                continue;
            }

            if ($mark > $maxMark) {
                $errors[] = [
                    'registration_id' =>
                        $registrationId,
                    'message' =>
                        'Mark cannot be greater than ' .
                        $maxMark .
                        '.',
                ];

                continue;
            }

            $upsertStmt->bind_param(
                'iiid',
                $registrationId,
                $subjectId,
                $semesterId,
                $mark
            );

            if (!$upsertStmt->execute()) {
                $errors[] = [
                    'registration_id' =>
                        $registrationId,
                    'message' =>
                        'Unable to save this result.',
                ];

                continue;
            }

            $savedCount++;
        }

        $upsertStmt->close();

        if (!empty($errors)) {
            $conn->rollback();

            jsonResponse(
                false,
                'Some marks are invalid. No results were saved.',
                [
                    'saved' => 0,
                    'skipped' => $skippedCount,
                    'errors' => $errors,
                ],
                422
            );
        }

        $conn->commit();

        jsonResponse(
            true,
            $savedCount > 0
                ? $savedCount .
                    ' result(s) saved successfully.'
                : 'No marks were entered.',
            [
                'saved' => $savedCount,
                'skipped' => $skippedCount,
                'semester' => [
                    'id' => $semesterId,
                    'name' =>
                        (string) $semester['name'],
                    'max_mark' => $maxMark,
                    'status' =>
                        (string) $semester['status'],
                ],
                'class' => [
                    'grade' => $grade,
                    'section' => $section,
                ],
                'subject_id' => $subjectId,
            ]
        );
    } catch (Throwable $e) {
        $conn->rollback();

        error_log(
            'BKHS teacher result save error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'An error occurred while saving results.',
            [],
            500
        );
    }
}

/*
|--------------------------------------------------------------------------
| GET FILTERS
|--------------------------------------------------------------------------
*/

$semesterId = isset($_GET['semester_id'])
    ? (int) $_GET['semester_id']
    : 0;

$grade = isset($_GET['grade'])
    ? (int) $_GET['grade']
    : 0;

$section = isset($_GET['section'])
    ? strtoupper(
        trim(
            (string) $_GET['section']
        )
    )
    : '';

$subjectId = isset($_GET['subject_id'])
    ? (int) $_GET['subject_id']
    : 0;

$search = isset($_GET['search'])
    ? trim(
        (string) $_GET['search']
    )
    : '';

$page = isset($_GET['page'])
    ? max(
        1,
        (int) $_GET['page']
    )
    : 1;

$perPage = 10;

/*
|--------------------------------------------------------------------------
| SEMESTERS
|--------------------------------------------------------------------------
*/

try {
    $semesterStmt = $conn->prepare(
        "
        SELECT
            id,
            name,
            order_number,
            max_mark,
            status,
            start_year,
            start_month,
            start_day,
            end_year,
            end_month,
            end_day
        FROM semesters
        WHERE academic_year_id = ?
        ORDER BY order_number ASC
        "
    );

    if (!$semesterStmt) {
        throw new RuntimeException(
            'Unable to prepare semester query.'
        );
    }

    $semesterStmt->bind_param(
        'i',
        $academicYearId
    );

    $semesterStmt->execute();

    $semesterResult =
        $semesterStmt->get_result();

    $semesters = [];
    $activeSemester = null;
    $selectedSemester = null;

    while (
        $semester =
        $semesterResult->fetch_assoc()
    ) {
        $semester['id'] =
            (int) $semester['id'];

        $semester['order_number'] =
            (int) $semester['order_number'];

        $semester['max_mark'] =
            (float) $semester['max_mark'];

        $semester['start_year'] =
            (int) $semester['start_year'];

        $semester['start_month'] =
            (int) $semester['start_month'];

        $semester['start_day'] =
            (int) $semester['start_day'];

        $semester['end_year'] =
            (int) $semester['end_year'];

        $semester['end_month'] =
            (int) $semester['end_month'];

        $semester['end_day'] =
            (int) $semester['end_day'];

        if (
            (string) $semester['status'] ===
            'Active'
        ) {
            $activeSemester = $semester;
        }

        if (
            $semesterId > 0 &&
            (int) $semester['id'] === $semesterId
        ) {
            $selectedSemester = $semester;
        }

        $semesters[] = $semester;
    }

    $semesterStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS teacher result semesters error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to load semesters.',
        [],
        500
    );
}

if (
    $semesterId === 0 &&
    $activeSemester !== null
) {
    $selectedSemester = $activeSemester;
    $semesterId =
        (int) $activeSemester['id'];
}

if (
    $semesterId > 0 &&
    $selectedSemester === null
) {
    jsonResponse(
        false,
        'Selected semester was not found in the active academic year.',
        [],
        400
    );
}

/*
|--------------------------------------------------------------------------
| TEACHER SUBJECT ASSIGNMENTS
|--------------------------------------------------------------------------
*/

try {
    $assignmentStmt = $conn->prepare(
        "
        SELECT
            sta.id,
            sta.grade,
            sta.section,
            sta.grade_subject_id,
            gs.subject_name
        FROM subject_teacher_assignments sta
        INNER JOIN grade_subjects gs
            ON gs.id = sta.grade_subject_id
        WHERE sta.teacher_user_id = ?
          AND sta.academic_year = ?
          AND sta.is_active = 1
          AND gs.is_active = 1
        ORDER BY
            sta.grade ASC,
            sta.section ASC,
            gs.subject_name ASC
        "
    );

    if (!$assignmentStmt) {
        throw new RuntimeException(
            'Unable to prepare teacher assignment query.'
        );
    }

    $assignmentStmt->bind_param(
        'is',
        $teacherUserId,
        $academicYearName
    );

    $assignmentStmt->execute();

    $assignmentResult =
        $assignmentStmt->get_result();

    $assignments = [];
    $gradesMap = [];
    $sectionsByGrade = [];
    $subjectsByClass = [];

    while (
        $assignment =
        $assignmentResult->fetch_assoc()
    ) {
        $assignmentId =
            (int) $assignment['id'];

        $assignmentGrade =
            (int) $assignment['grade'];

        $assignmentSection =
            strtoupper(
                trim(
                    (string) $assignment['section']
                )
            );

        $gradeSubjectId =
            (int) $assignment['grade_subject_id'];

        $subjectName =
            (string) $assignment['subject_name'];

        $assignments[] = [
            'id' => $assignmentId,
            'grade' => $assignmentGrade,
            'section' => $assignmentSection,
            'grade_subject_id' =>
                $gradeSubjectId,
            'subject_name' => $subjectName,
        ];

        $gradesMap[$assignmentGrade] =
            $assignmentGrade;

        if (
            !isset(
                $sectionsByGrade[$assignmentGrade]
            )
        ) {
            $sectionsByGrade[$assignmentGrade] =
                [];
        }

        if (
            !in_array(
                $assignmentSection,
                $sectionsByGrade[$assignmentGrade],
                true
            )
        ) {
            $sectionsByGrade[$assignmentGrade][] =
                $assignmentSection;
        }

        $classKey =
            $assignmentGrade .
            '|' .
            $assignmentSection;

        if (
            !isset(
                $subjectsByClass[$classKey]
            )
        ) {
            $subjectsByClass[$classKey] = [];
        }

        $subjectsByClass[$classKey][] = [
            'id' => $gradeSubjectId,
            'name' => $subjectName,
        ];
    }

    $assignmentStmt->close();
} catch (Throwable $e) {
    error_log(
        'BKHS teacher result assignments error: ' .
        $e->getMessage()
    );

    jsonResponse(
        false,
        'Unable to load teacher subject assignments.',
        [],
        500
    );
}

$grades = array_values($gradesMap);

sort($grades);

foreach (
    $sectionsByGrade as &$gradeSections
) {
    sort($gradeSections);
}

unset($gradeSections);

/*
|--------------------------------------------------------------------------
| SELECTED ASSIGNMENT
|--------------------------------------------------------------------------
*/

$selectedAssignment = null;

if (
    $grade > 0 &&
    $section !== '' &&
    $subjectId > 0
) {
    foreach (
        $assignments as $assignment
    ) {
        if (
            (int) $assignment['grade'] ===
                $grade &&
            strtoupper(
                (string) $assignment['section']
            ) === $section &&
            (int) $assignment['grade_subject_id'] ===
                $subjectId
        ) {
            $selectedAssignment =
                $assignment;

            break;
        }
    }

    if (
        $selectedAssignment === null
    ) {
        jsonResponse(
            false,
            'The selected class and subject are not assigned to this teacher.',
            [],
            403
        );
    }
}

/*
|--------------------------------------------------------------------------
| SELECTED SUBJECT
|--------------------------------------------------------------------------
*/

$selectedSubject = null;

if ($subjectId > 0) {
    foreach (
        $assignments as $assignment
    ) {
        if (
            (int) $assignment['grade_subject_id'] !==
            $subjectId
        ) {
            continue;
        }

        if (
            $grade > 0 &&
            (int) $assignment['grade'] !==
                $grade
        ) {
            continue;
        }

        if (
            $section !== '' &&
            strtoupper(
                (string) $assignment['section']
            ) !== $section
        ) {
            continue;
        }

        $selectedSubject = [
            'id' =>
                (int) $assignment['grade_subject_id'],
            'name' =>
                (string) $assignment['subject_name'],
        ];

        break;
    }
}

/*
|--------------------------------------------------------------------------
| STUDENTS
|--------------------------------------------------------------------------
*/

$students = [];

$totalStudents = 0;
$recordedResults = 0;
$missingResults = 0;
$totalPages = 0;

if (
    $selectedSemester !== null &&
    $grade > 0 &&
    $section !== '' &&
    $subjectId > 0 &&
    $selectedAssignment !== null
) {
    /*
    |--------------------------------------------------------------------------
    | COUNT STUDENTS
    |--------------------------------------------------------------------------
    */

    $countSql = "
        SELECT COUNT(*)
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        INNER JOIN grades g
            ON g.id = sr.grade_id
        INNER JOIN sections sec
            ON sec.id = sr.section_id
        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
    ";

    if ($search !== '') {
        $countSql .= "
            AND (
                s.full_name LIKE ?
                OR s.student_code LIKE ?
            )
        ";
    }

    try {
        $countStmt =
            $conn->prepare($countSql);

        if (!$countStmt) {
            throw new RuntimeException(
                'Unable to prepare student count query.'
            );
        }

        if ($search !== '') {
            $searchValue =
                '%' . $search . '%';

            $countStmt->bind_param(
                'iisss',
                $academicYearId,
                $grade,
                $section,
                $searchValue,
                $searchValue
            );
        } else {
            $countStmt->bind_param(
                'iis',
                $academicYearId,
                $grade,
                $section
            );
        }

        $countStmt->execute();

        $countResult =
            $countStmt->get_result();

        $countRow =
            $countResult->fetch_row();

        $totalStudents =
            (int) ($countRow[0] ?? 0);

        $countStmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teacher result student count error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to count students.',
            [],
            500
        );
    }

    $totalPages =
        $totalStudents > 0
            ? (int) ceil(
                $totalStudents / $perPage
            )
            : 0;

    if (
        $totalPages > 0 &&
        $page > $totalPages
    ) {
        $page = $totalPages;
    }

    $offset =
        ($page - 1) *
        $perPage;

    /*
    |--------------------------------------------------------------------------
    | STUDENTS + RESULTS
    |--------------------------------------------------------------------------
    */

    $studentSql = "
        SELECT
            sr.id AS registration_id,
            s.id AS student_id,
            s.student_code,
            s.full_name,
            r.id AS result_id,
            r.mark
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        INNER JOIN grades g
            ON g.id = sr.grade_id
        INNER JOIN sections sec
            ON sec.id = sr.section_id
        LEFT JOIN results r
            ON r.student_registration_id = sr.id
            AND r.grade_subject_id = ?
            AND r.semester_id = ?
        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
    ";

    if ($search !== '') {
        $studentSql .= "
            AND (
                s.full_name LIKE ?
                OR s.student_code LIKE ?
            )
        ";
    }

    $studentSql .= "
        ORDER BY s.full_name ASC
        LIMIT ? OFFSET ?
    ";

    try {
        $studentStmt =
            $conn->prepare($studentSql);

        if (!$studentStmt) {
            throw new RuntimeException(
                'Unable to prepare student result query.'
            );
        }

        if ($search !== '') {
            $searchValue =
                '%' . $search . '%';

            $studentStmt->bind_param(
                'iiiisssii',
                $subjectId,
                $semesterId,
                $academicYearId,
                $grade,
                $section,
                $searchValue,
                $searchValue,
                $perPage,
                $offset
            );
        } else {
            $studentStmt->bind_param(
                'iiiisii',
                $subjectId,
                $semesterId,
                $academicYearId,
                $grade,
                $section,
                $perPage,
                $offset
            );
        }

        $studentStmt->execute();

        $studentResult =
            $studentStmt->get_result();

        while (
            $student =
            $studentResult->fetch_assoc()
        ) {
            $resultId =
                $student['result_id'] !== null
                    ? (int) $student['result_id']
                    : null;

            $mark =
                $student['mark'] !== null
                    ? (float) $student['mark']
                    : null;

            $isRecorded =
                $resultId !== null &&
                $mark !== null;

            $students[] = [
                'registration_id' =>
                    (int) $student['registration_id'],
                'student_id' =>
                    (int) $student['student_id'],
                'student_code' =>
                    (string) $student['student_code'],
                'full_name' =>
                    (string) $student['full_name'],
                'result_id' =>
                    $resultId,
                'mark' =>
                    $mark,
                'status' =>
                    $isRecorded
                        ? 'Recorded'
                        : 'Missing',
            ];
        }

        $studentStmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teacher result student query error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to load students and results.',
            [],
            500
        );
    }

    /*
    |--------------------------------------------------------------------------
    | SUMMARY
    |--------------------------------------------------------------------------
    */

    $summarySql = "
        SELECT
            COUNT(*) AS total_students,
            COALESCE(
                SUM(
                    CASE
                        WHEN r.id IS NOT NULL
                         AND r.mark IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS recorded_results
        FROM student_registrations sr
        INNER JOIN students s
            ON s.id = sr.student_id
        INNER JOIN grades g
            ON g.id = sr.grade_id
        INNER JOIN sections sec
            ON sec.id = sr.section_id
        LEFT JOIN results r
            ON r.student_registration_id = sr.id
            AND r.grade_subject_id = ?
            AND r.semester_id = ?
        WHERE sr.academic_year_id = ?
          AND g.grade_number = ?
          AND sec.code = ?
    ";

    if ($search !== '') {
        $summarySql .= "
            AND (
                s.full_name LIKE ?
                OR s.student_code LIKE ?
            )
        ";
    }

    try {
        $summaryStmt =
            $conn->prepare($summarySql);

        if (!$summaryStmt) {
            throw new RuntimeException(
                'Unable to prepare result summary query.'
            );
        }

        if ($search !== '') {
            $searchValue =
                '%' . $search . '%';

            $summaryStmt->bind_param(
                'iiiisss',
                $subjectId,
                $semesterId,
                $academicYearId,
                $grade,
                $section,
                $searchValue,
                $searchValue
            );
        } else {
            $summaryStmt->bind_param(
                'iiiis',
                $subjectId,
                $semesterId,
                $academicYearId,
                $grade,
                $section
            );
        }

        $summaryStmt->execute();

        $summaryResult =
            $summaryStmt->get_result();

        $summary =
            $summaryResult->fetch_assoc();

        $summaryStmt->close();
    } catch (Throwable $e) {
        error_log(
            'BKHS teacher result summary error: ' .
            $e->getMessage()
        );

        jsonResponse(
            false,
            'Unable to load result summary.',
            [],
            500
        );
    }

    $totalStudents =
        (int) ($summary['total_students'] ?? 0);

    $recordedResults =
        (int) ($summary['recorded_results'] ?? 0);

    $missingResults =
        max(
            0,
            $totalStudents -
            $recordedResults
        );

    $totalPages =
        $totalStudents > 0
            ? (int) ceil(
                $totalStudents / $perPage
            )
            : 0;

    if (
        $totalPages > 0 &&
        $page > $totalPages
    ) {
        $page = $totalPages;
    }
}

/*
|--------------------------------------------------------------------------
| PERMISSIONS
|--------------------------------------------------------------------------
*/

$isActiveSemester =
    $selectedSemester !== null &&
    (string) $selectedSemester['status'] ===
        'Active';

$isCompletedSemester =
    $selectedSemester !== null &&
    (string) $selectedSemester['status'] ===
        'Completed';

$isNotCompletedSemester =
    $selectedSemester !== null &&
    (string) $selectedSemester['status'] ===
        'Not Completed';

$canEdit =
    $isActiveSemester;

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

jsonResponse(
    true,
    'Teacher result data loaded successfully.',
    [
        'teacher' => [
            'user_id' =>
                (int) $teacher['user_id'],
            'full_name' =>
                (string) $teacher['full_name'],
            'email' =>
                (string) $teacher['email'],
            'phone' =>
                (string) $teacher['phone'],
            'photo_path' =>
                $teacherPhoto,
            'initials' =>
                getInitials(
                    (string) $teacher['full_name']
                ),
        ],

        'academic_year' => [
            'id' =>
                $academicYearId,
            'name' =>
                $academicYearName,
            'status' =>
                (string) $academicYear['status'],
            'start_year' =>
                (int) $academicYear['start_year'],
            'start_month' =>
                (int) $academicYear['start_month'],
            'start_day' =>
                (int) $academicYear['start_day'],
            'end_year' =>
                (int) $academicYear['end_year'],
            'end_month' =>
                (int) $academicYear['end_month'],
            'end_day' =>
                (int) $academicYear['end_day'],
        ],

        'semesters' =>
            $semesters,

        'active_semester' =>
            $activeSemester,

        'selected_semester' =>
            $selectedSemester,

        'assignments' =>
            $assignments,

        'grades' =>
            $grades,

        'sections_by_grade' =>
            $sectionsByGrade,

        'subjects_by_class' =>
            $subjectsByClass,

        'selected' => [
            'semester_id' =>
                $semesterId,
            'grade' =>
                $grade,
            'section' =>
                $section,
            'subject_id' =>
                $subjectId,
        ],

        'subject' =>
            $selectedSubject,

        'summary' => [
            'students' =>
                $totalStudents,
            'recorded' =>
                $recordedResults,
            'missing' =>
                $missingResults,
        ],

        'students' =>
            $students,

        'pagination' => [
            'current_page' =>
                $page,
            'per_page' =>
                $perPage,
            'total_students' =>
                $totalStudents,
            'total_pages' =>
                $totalPages,
        ],

        'permissions' => [
            'is_active_semester' =>
                $isActiveSemester,
            'is_completed_semester' =>
                $isCompletedSemester,
            'is_not_completed_semester' =>
                $isNotCompletedSemester,
            'can_edit' =>
                $canEdit,
        ],
    ]
);
