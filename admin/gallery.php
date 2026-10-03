<?php

/*
|--------------------------------------------------------------------------
| BKHS - Admin Gallery Management
|--------------------------------------------------------------------------
| File:
| C:\xampp\htdocs\BKHS\admin\gallery.php
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true ||
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    strtolower((string) $_SESSION['role']) !== 'admin'
) {
    header('Location: ../auth/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Includes
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/EthiopianCalendar.php';

/*
|--------------------------------------------------------------------------
| Database validation
|--------------------------------------------------------------------------
*/

if (!isset($conn) || !($conn instanceof mysqli)) {
    die(
        '<div style="font-family:Arial;padding:30px;color:#b91c1c;">
            <h2>Database Connection Error</h2>
            <p>The database connection variable <strong>$conn</strong> was not found.</p>
            <p>Please check <strong>config/database.php</strong>.</p>
        </div>'
    );
}

$conn->set_charset('utf8mb4');

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectGallery(string $type, string $message): void
{
    header(
        'Location: gallery.php?' .
        http_build_query([
            'message_type' => $type,
            'message' => $message
        ])
    );
    exit;
}

function galleryCategoryName(string $category): string
{
    $categories = [
        'events'        => 'Events',
        'computer-lab'  => 'Computer Lab',
        'library'       => 'Library',
        'science-lab'   => 'Science Lab',
        'classrooms'    => 'Classrooms',
        'campus'        => 'School Campus',
        'sports'        => 'Sports',
        'cultural'      => 'Cultural Activities',
        'graduation'    => 'Graduation'
    ];

    return $categories[$category] ?? ucfirst(str_replace('-', ' ', $category));
}

function deleteGalleryFile(string $relativePath): bool
{
    if ($relativePath === '') {
        return false;
    }

    $baseDirectory = realpath(
        __DIR__ . '/../public/gallery'
    );

    if ($baseDirectory === false) {
        return false;
    }

    $filePath = __DIR__ . '/../public/' . ltrim($relativePath, '/\\');

    $realFile = realpath($filePath);

    if ($realFile === false || !is_file($realFile)) {
        return false;
    }

    /*
     * Make sure the file is really inside public/gallery.
     */
    $baseWithSeparator = rtrim($baseDirectory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;

    if (strpos($realFile, $baseWithSeparator) !== 0) {
        return false;
    }

    return @unlink($realFile);
}

function uploadGalleryImage(
    array $file,
    string $category,
    string $baseUploadDirectory
): array {

    if (!isset($file['error'])) {
        return [
            'success' => false,
            'message' => 'Invalid upload request.'
        ];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {

        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'The uploaded image is too large.',
            UPLOAD_ERR_FORM_SIZE  => 'The uploaded image is too large.',
            UPLOAD_ERR_PARTIAL    => 'The image upload was incomplete.',
            UPLOAD_ERR_NO_FILE    => 'Please select an image.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the image.',
            UPLOAD_ERR_EXTENSION  => 'The upload was stopped by a PHP extension.'
        ];

        return [
            'success' => false,
            'message' => $errors[$file['error']] ?? 'Image upload failed.'
        ];
    }

    /*
     * Maximum 5 MB.
     */
    if ((int) $file['size'] > 5 * 1024 * 1024) {
        return [
            'success' => false,
            'message' => 'Image size must not exceed 5 MB.'
        ];
    }

    /*
     * Verify actual image.
     */
    $imageInfo = @getimagesize($file['tmp_name']);

    if ($imageInfo === false) {
        return [
            'success' => false,
            'message' => 'The selected file is not a valid image.'
        ];
    }

    /*
     * MIME validation.
     */
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset($allowedMimeTypes[$mimeType])) {
        return [
            'success' => false,
            'message' => 'Only JPG, PNG, and WEBP images are allowed.'
        ];
    }

    /*
     * Make category directory.
     */
    $categoryDirectory = $baseUploadDirectory
        . DIRECTORY_SEPARATOR
        . $category;

    if (!is_dir($categoryDirectory)) {
        if (!mkdir($categoryDirectory, 0755, true)) {
            return [
                'success' => false,
                'message' => 'Could not create the gallery upload directory.'
            ];
        }
    }

    if (!is_writable($categoryDirectory)) {
        return [
            'success' => false,
            'message' => 'Gallery upload directory is not writable.'
        ];
    }

    /*
     * Generate unique filename.
     */
    try {
        $randomName = bin2hex(random_bytes(12));
    } catch (Exception $exception) {
        $randomName = uniqid('', true);
    }

    $extension = $allowedMimeTypes[$mimeType];

    $filename = date('YmdHis')
        . '_'
        . $randomName
        . '.'
        . $extension;

    $destination = $categoryDirectory
        . DIRECTORY_SEPARATOR
        . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return [
            'success' => false,
            'message' => 'Could not save the uploaded image.'
        ];
    }

    /*
     * Path stored in database.
     *
     * Example:
     * gallery/events/20260919093000_xxxxx.jpg
     */
    $relativePath = 'gallery/'
        . $category
        . '/'
        . $filename;

    return [
        'success' => true,
        'path' => $relativePath
    ];
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['gallery_csrf'])) {
    try {
        $_SESSION['gallery_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $exception) {
        $_SESSION['gallery_csrf'] = md5(uniqid('', true));
    }
}

$csrfToken = $_SESSION['gallery_csrf'];

/*
|--------------------------------------------------------------------------
| Categories
|--------------------------------------------------------------------------
*/

$categories = [
    'events' => 'Events',
    'computer-lab' => 'Computer Lab',
    'library' => 'Library',
    'science-lab' => 'Science Lab',
    'classrooms' => 'Classrooms',
    'campus' => 'School Campus',
    'sports' => 'Sports',
    'cultural' => 'Cultural Activities',
    'graduation' => 'Graduation'
];

/*
|--------------------------------------------------------------------------
| Create gallery directories
|--------------------------------------------------------------------------
*/

$galleryRoot = __DIR__ . '/../public/gallery';

if (!is_dir($galleryRoot)) {
    @mkdir($galleryRoot, 0755, true);
}

foreach ($categories as $categoryKey => $categoryName) {
    $categoryDirectory = $galleryRoot
        . DIRECTORY_SEPARATOR
        . $categoryKey;

    if (!is_dir($categoryDirectory)) {
        @mkdir($categoryDirectory, 0755, true);
    }
}

/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = isset($_GET['message'])
    ? (string) $_GET['message']
    : '';

$messageType = isset($_GET['message_type'])
    ? (string) $_GET['message_type']
    : '';

/*
|--------------------------------------------------------------------------
| Handle POST Requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $postedToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';

    if (
        empty($postedToken) ||
        empty($_SESSION['gallery_csrf']) ||
        !hash_equals($_SESSION['gallery_csrf'], $postedToken)
    ) {
        redirectGallery('danger', 'Invalid security token. Please try again.');
    }

    $action = isset($_POST['action'])
        ? trim((string) $_POST['action'])
        : '';

    /*
    |--------------------------------------------------------------------------
    | ADD PHOTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        $title = isset($_POST['title'])
            ? trim((string) $_POST['title'])
            : '';

        $description = isset($_POST['description'])
            ? trim((string) $_POST['description'])
            : '';

        $category = isset($_POST['category'])
            ? trim((string) $_POST['category'])
            : '';

        if ($title === '') {
            redirectGallery('danger', 'Photo title is required.');
        }

        if (strlen($title) > 255) {
            redirectGallery('danger', 'Photo title is too long.');
        }

        if (!isset($categories[$category])) {
            redirectGallery('danger', 'Please select a valid gallery category.');
        }

        if (
            !isset($_FILES['image']) ||
            !is_array($_FILES['image'])
        ) {
            redirectGallery('danger', 'Please select an image.');
        }

        $uploadResult = uploadGalleryImage(
            $_FILES['image'],
            $category,
            $galleryRoot
        );

        if (!$uploadResult['success']) {
            redirectGallery(
                'danger',
                $uploadResult['message']
            );
        }

        $imagePath = $uploadResult['path'];

        $sql = "
            INSERT INTO gallery
            (
                title,
                description,
                category,
                image_path
            )
            VALUES
            (
                ?,
                ?,
                ?,
                ?
            )
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {

            deleteGalleryFile($imagePath);

            redirectGallery(
                'danger',
                'Database error: ' . $conn->error
            );
        }

        $stmt->bind_param(
            'ssss',
            $title,
            $description,
            $category,
            $imagePath
        );

        if (!$stmt->execute()) {

            $stmt->close();

            deleteGalleryFile($imagePath);

            redirectGallery(
                'danger',
                'Could not save photo: ' . $conn->error
            );
        }

        $stmt->close();

        redirectGallery(
            'success',
            'Photo added successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT PHOTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'edit') {

        $id = isset($_POST['id'])
            ? (int) $_POST['id']
            : 0;

        $title = isset($_POST['title'])
            ? trim((string) $_POST['title'])
            : '';

        $description = isset($_POST['description'])
            ? trim((string) $_POST['description'])
            : '';

        $category = isset($_POST['category'])
            ? trim((string) $_POST['category'])
            : '';

        if ($id <= 0) {
            redirectGallery('danger', 'Invalid photo ID.');
        }

        if ($title === '') {
            redirectGallery('danger', 'Photo title is required.');
        }

        if (strlen($title) > 255) {
            redirectGallery('danger', 'Photo title is too long.');
        }

        if (!isset($categories[$category])) {
            redirectGallery('danger', 'Please select a valid category.');
        }

        /*
         * Get old photo.
         */
        $stmt = $conn->prepare(
            "SELECT image_path FROM gallery WHERE id = ? LIMIT 1"
        );

        if (!$stmt) {
            redirectGallery(
                'danger',
                'Database error: ' . $conn->error
            );
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $result = $stmt->get_result();
        $existingPhoto = $result->fetch_assoc();

        $stmt->close();

        if (!$existingPhoto) {
            redirectGallery(
                'danger',
                'The selected photo was not found.'
            );
        }

        $oldImagePath = (string) $existingPhoto['image_path'];

        $newImagePath = $oldImagePath;
        $uploadedNewImage = false;

        /*
         * If replacement image was selected.
         */
        if (
            isset($_FILES['image']) &&
            isset($_FILES['image']['error']) &&
            $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE
        ) {

            $uploadResult = uploadGalleryImage(
                $_FILES['image'],
                $category,
                $galleryRoot
            );

            if (!$uploadResult['success']) {
                redirectGallery(
                    'danger',
                    $uploadResult['message']
                );
            }

            $newImagePath = $uploadResult['path'];
            $uploadedNewImage = true;
        }

        /*
         * Update database.
         */
        $stmt = $conn->prepare(
            "
            UPDATE gallery
            SET
                title = ?,
                description = ?,
                category = ?,
                image_path = ?
            WHERE id = ?
            "
        );

        if (!$stmt) {

            if ($uploadedNewImage) {
                deleteGalleryFile($newImagePath);
            }

            redirectGallery(
                'danger',
                'Database error: ' . $conn->error
            );
        }

        $stmt->bind_param(
            'ssssi',
            $title,
            $description,
            $category,
            $newImagePath,
            $id
        );

        if (!$stmt->execute()) {

            $stmt->close();

            if ($uploadedNewImage) {
                deleteGalleryFile($newImagePath);
            }

            redirectGallery(
                'danger',
                'Could not update photo: ' . $conn->error
            );
        }

        $stmt->close();

        /*
         * Delete old image only after successful DB update.
         */
        if (
            $uploadedNewImage &&
            $oldImagePath !== '' &&
            $oldImagePath !== $newImagePath
        ) {
            deleteGalleryFile($oldImagePath);
        }

        redirectGallery(
            'success',
            'Photo updated successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE PHOTO
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $id = isset($_POST['id'])
            ? (int) $_POST['id']
            : 0;

        if ($id <= 0) {
            redirectGallery('danger', 'Invalid photo ID.');
        }

        /*
         * Get image path first.
         */
        $stmt = $conn->prepare(
            "SELECT image_path FROM gallery WHERE id = ? LIMIT 1"
        );

        if (!$stmt) {
            redirectGallery(
                'danger',
                'Database error: ' . $conn->error
            );
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();

        $result = $stmt->get_result();
        $photo = $result->fetch_assoc();

        $stmt->close();

        if (!$photo) {
            redirectGallery(
                'danger',
                'Photo not found.'
            );
        }

        $imagePath = (string) $photo['image_path'];

        /*
         * Delete database record.
         */
        $stmt = $conn->prepare(
            "DELETE FROM gallery WHERE id = ? LIMIT 1"
        );

        if (!$stmt) {
            redirectGallery(
                'danger',
                'Database error: ' . $conn->error
            );
        }

        $stmt->bind_param('i', $id);

        if (!$stmt->execute()) {

            $stmt->close();

            redirectGallery(
                'danger',
                'Could not delete photo: ' . $conn->error
            );
        }

        $stmt->close();

        /*
         * Delete physical image.
         */
        if ($imagePath !== '') {
            deleteGalleryFile($imagePath);
        }

        redirectGallery(
            'success',
            'Photo deleted successfully.'
        );
    }
}

/*
|--------------------------------------------------------------------------
| Search / Filter
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim((string) $_GET['search'])
    : '';

$filterCategory = isset($_GET['category'])
    ? trim((string) $_GET['category'])
    : '';

/*
|--------------------------------------------------------------------------
| Build Gallery Query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        id,
        title,
        description,
        category,
        image_path,
        created_at,
        updated_at
    FROM gallery
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {

    $sql .= "
        AND (
            title LIKE ?
            OR description LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;

    $types .= 'ss';
}

if (
    $filterCategory !== '' &&
    isset($categories[$filterCategory])
) {

    $sql .= " AND category = ? ";

    $params[] = $filterCategory;
    $types .= 's';
}

$sql .= " ORDER BY created_at DESC, id DESC";

/*
|--------------------------------------------------------------------------
| Prepare gallery query
|--------------------------------------------------------------------------
*/

$stmt = $conn->prepare($sql);

if (!$stmt) {

    die(
        '<div style="font-family:Arial;padding:30px;color:#b91c1c;">
            <h2>Gallery Database Error</h2>
            <p>Could not prepare the gallery query.</p>
            <p><strong>' .
            e($conn->error) .
            '</strong></p>
            <p>Make sure the <strong>gallery</strong> table exists.</p>
        </div>'
    );
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

if (!$stmt->execute()) {

    die(
        '<div style="font-family:Arial;padding:30px;color:#b91c1c;">
            <h2>Gallery Query Error</h2>
            <p>' .
            e($stmt->error) .
            '</p>
        </div>'
    );
}

$result = $stmt->get_result();

$photos = [];

while ($row = $result->fetch_assoc()) {
    $photos[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| Total Gallery Photos
|--------------------------------------------------------------------------
*/

$totalPhotos = 0;

$countResult = $conn->query(
    "SELECT COUNT(*) AS total FROM gallery"
);

if ($countResult) {

    $countRow = $countResult->fetch_assoc();

    $totalPhotos = isset($countRow['total'])
        ? (int) $countRow['total']
        : 0;

    $countResult->free();
}

/*
|--------------------------------------------------------------------------
| Category Counts
|--------------------------------------------------------------------------
*/

$categoryCounts = [];

foreach ($categories as $key => $name) {
    $categoryCounts[$key] = 0;
}

$countCategoriesResult = $conn->query(
    "
    SELECT category, COUNT(*) AS total
    FROM gallery
    GROUP BY category
    "
);

if ($countCategoriesResult) {

    while ($row = $countCategoriesResult->fetch_assoc()) {

        $categoryKey = (string) $row['category'];

        if (isset($categoryCounts[$categoryKey])) {
            $categoryCounts[$categoryKey] = (int) $row['total'];
        }
    }

    $countCategoriesResult->free();
}

/*
|--------------------------------------------------------------------------
| Admin Name
|--------------------------------------------------------------------------
*/

$adminName = isset($_SESSION['full_name'])
    ? (string) $_SESSION['full_name']
    : 'Administrator';

$adminInitial = strtoupper(
    substr(trim($adminName), 0, 1)
);

/*
|--------------------------------------------------------------------------
| Ethiopian Date Helper
|--------------------------------------------------------------------------
*/

function formatEthiopianDate($dateTime): string
{
    if (empty($dateTime)) {
        return '-';
    }

    $timestamp = strtotime((string) $dateTime);

    if ($timestamp === false) {
        return '-';
    }

    try {

        $gregorianDate = date('Y-m-d', $timestamp);

        $ethiopian = EthiopianCalendar::fromGregorian(
            $gregorianDate
        );

        if (is_object($ethiopian)) {

            if (method_exists($ethiopian, 'format')) {
                return $ethiopian->format('en');
            }
        }

        if (is_array($ethiopian)) {

            $year = $ethiopian['year']
                ?? $ethiopian['y']
                ?? '';

            $month = $ethiopian['month']
                ?? $ethiopian['m']
                ?? '';

            $day = $ethiopian['day']
                ?? $ethiopian['d']
                ?? '';

            if ($year !== '' && $month !== '' && $day !== '') {

                $monthNames = [
                    1 => 'Meskerem',
                    2 => 'Tikimt',
                    3 => 'Hidar',
                    4 => 'Tahsas',
                    5 => 'Tir',
                    6 => 'Yekatit',
                    7 => 'Megabit',
                    8 => 'Miyazya',
                    9 => 'Ginbot',
                    10 => 'Sene',
                    11 => 'Hamle',
                    12 => 'Nehase',
                    13 => 'Pagume'
                ];

                $monthName = $monthNames[(int) $month]
                    ?? (string) $month;

                return $day
                    . ' '
                    . $monthName
                    . ' '
                    . $year;
            }
        }

    } catch (Throwable $exception) {
        /*
         * Keep page working if calendar formatting fails.
         */
    }

    return date('d M Y', $timestamp);
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

    <title>Gallery Management | BKHS</title>
       <link
        rel="icon"
        type="image/webp"
        href="../public/image/logo.webp"
    >

    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <!-- Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <style>

        :root {
            --sidebar-width: 260px;
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --sidebar: #111827;
            --sidebar-hover: #1f2937;
            --border: #e5e7eb;
            --background: #f8fafc;
            --text: #111827;
            --muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--background);
            color: var(--text);
            font-family: "Inter", sans-serif;
        }

        /*
        |--------------------------------------------------------------------------
        | Sidebar
        |--------------------------------------------------------------------------
        */

        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: var(--sidebar);
            color: #fff;
            z-index: 1050;
            overflow-y: auto;
            transition: transform .25s ease;
        }

        .sidebar-brand {
            height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 20px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            object-fit: cover;
            background: #fff;
        }

        .brand-title {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }

        .brand-subtitle {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 2px;
        }

        .sidebar-nav {
            padding: 18px 12px;
        }

        .nav-section-title {
            color: #6b7280;
            text-transform: uppercase;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            padding: 12px 12px 7px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 11px 13px;
            margin-bottom: 4px;
            border-radius: 9px;
            color: #d1d5db;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all .2s ease;
        }

        .sidebar-link i {
            font-size: 17px;
            width: 21px;
            text-align: center;
        }

        .sidebar-link:hover {
            color: #fff;
            background: var(--sidebar-hover);
        }

        .sidebar-link.active {
            background: rgba(37, 99, 235, .18);
            color: #fff;
        }

        .sidebar-link.active i {
            color: #60a5fa;
        }

        /*
        |--------------------------------------------------------------------------
        | Main
        |--------------------------------------------------------------------------
        */

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 1000;
            height: 76px;
            background: rgba(255,255,255,.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 28px;
        }

        .page-title-small {
            font-size: 14px;
            font-weight: 700;
            margin: 0;
        }

        .page-subtitle-small {
            font-size: 11px;
            color: var(--muted);
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .admin-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: #dbeafe;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }

        .admin-name {
            font-size: 13px;
            font-weight: 600;
        }

        .admin-role {
            font-size: 10px;
            color: var(--muted);
        }

        .page-container {
            padding: 28px;
        }

        /*
        |--------------------------------------------------------------------------
        | Page Header
        |--------------------------------------------------------------------------
        */

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            margin-bottom: 24px;
        }

        .page-heading h1 {
            font-size: 25px;
            font-weight: 700;
            margin: 0 0 5px;
        }

        .page-heading p {
            color: var(--muted);
            margin: 0;
            font-size: 13px;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        /*
        |--------------------------------------------------------------------------
        | Stat Cards
        |--------------------------------------------------------------------------
        */

        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            height: 100%;
        }

        .stat-icon {
            width: 43px;
            height: 43px;
            border-radius: 11px;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 13px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            line-height: 1;
        }

        .stat-label {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }

        /*
        |--------------------------------------------------------------------------
        | Filter Card
        |--------------------------------------------------------------------------
        */

        .filter-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 18px;
            margin-top: 24px;
            margin-bottom: 24px;
        }

        /*
        |--------------------------------------------------------------------------
        | Gallery
        |--------------------------------------------------------------------------
        */

        .gallery-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            height: 100%;
            transition: transform .2s ease, box-shadow .2s ease;
        }

        .gallery-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(15, 23, 42, .08);
        }

        .gallery-image-wrapper {
            position: relative;
            height: 210px;
            background: #f1f5f9;
            overflow: hidden;
            cursor: pointer;
        }

        .gallery-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform .3s ease;
        }

        .gallery-card:hover .gallery-image {
            transform: scale(1.04);
        }

        .gallery-category {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(17,24,39,.85);
            color: #fff;
            border-radius: 20px;
            padding: 5px 10px;
            font-size: 10px;
            font-weight: 600;
            backdrop-filter: blur(5px);
        }

        .gallery-body {
            padding: 16px;
        }

        .gallery-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .gallery-description {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
            min-height: 36px;
        }

        .gallery-meta {
            border-top: 1px solid var(--border);
            margin-top: 13px;
            padding-top: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--muted);
            font-size: 10px;
        }

        .gallery-actions {
            display: flex;
            gap: 5px;
        }

        .gallery-actions .btn {
            width: 32px;
            height: 32px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /*
        |--------------------------------------------------------------------------
        | Empty State
        |--------------------------------------------------------------------------
        */

        .empty-state {
            background: #fff;
            border: 1px dashed #cbd5e1;
            border-radius: 14px;
            padding: 60px 20px;
            text-align: center;
        }

        .empty-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: #eff6ff;
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 30px;
        }

        .empty-state h3 {
            font-size: 18px;
            font-weight: 700;
        }

        .empty-state p {
            color: var(--muted);
            font-size: 13px;
        }

        /*
        |--------------------------------------------------------------------------
        | Mobile
        |--------------------------------------------------------------------------
        */

        .mobile-menu-btn {
            display: none;
            border: 0;
            background: transparent;
            font-size: 23px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1040;
        }

        @media (max-width: 991.98px) {

            .sidebar {
                transform: translateX(-100%);
            }

            .sidebar.show {
                transform: translateX(0);
            }

            .sidebar-overlay.show {
                display: block;
            }

            .main-content {
                margin-left: 0;
            }

            .mobile-menu-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }

            .topbar {
                padding: 0 18px;
            }

            .page-container {
                padding: 20px;
            }

            .page-header {
                flex-direction: column;
            }

            .page-header .btn {
                width: 100%;
            }
        }

        @media (max-width: 575.98px) {

            .page-container {
                padding: 15px;
            }

            .topbar {
                height: 68px;
            }

            .admin-name,
            .admin-role {
                display: none;
            }

            .gallery-image-wrapper {
                height: 190px;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Modal
        |--------------------------------------------------------------------------
        */

        .modal-content {
            border: 0;
            border-radius: 16px;
            overflow: hidden;
        }

        .modal-header {
            border-bottom: 1px solid var(--border);
            padding: 18px 20px;
        }

        .modal-body {
            padding: 20px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .form-control,
        .form-select {
            border-color: #dbe2ea;
            border-radius: 9px;
            font-size: 13px;
            min-height: 42px;
        }

        textarea.form-control {
            min-height: 100px;
        }

        .image-preview {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 10px;
            border: 1px solid var(--border);
            display: none;
            margin-top: 10px;
        }

    </style>

</head>

<body>

<!-- ================================================================
     SIDEBAR
================================================================ -->

<aside
    class="sidebar"
    id="sidebar"
>

    <div class="sidebar-brand">

        <img
            src="../public/logo.webp"
            alt="BKHS"
            class="brand-logo"
            onerror="this.style.display='none';"
        >

        <div>
            <div class="brand-title">
                BKHS
            </div>

            <div class="brand-subtitle">
                School Management
            </div>
        </div>

    </div>

    <nav class="sidebar-nav">

        <div class="nav-section-title">
            Main
        </div>

        <a
            href="dashboard.php"
            class="sidebar-link"
        >
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>

        <a
            href="users/index.php"
            class="sidebar-link"
        >
            <i class="bi bi-people-fill"></i>
            <span>Users</span>
        </a>

        <a
            href="academic-calendar/index.php"
            class="sidebar-link"
        >
            <i class="bi bi-calendar3"></i>
            <span>Academic Calendar</span>
        </a>

        <a
            href="subjectassignment.php"
            class="sidebar-link"
        >
            <i class="bi bi-journal-bookmark-fill"></i>
            <span>Subject Assignment</span>
        </a>

        <div class="nav-section-title">
            School
        </div>

        <a
            href="gallery.php"
            class="sidebar-link active"
        >
            <i class="bi bi-images"></i>
            <span>Gallery</span>
        </a>

      


        <div class="nav-section-title">
            Account
        </div>

        <a
            href="profile.php"
            class="sidebar-link"
        >
            <i class="bi bi-person-circle"></i>
            <span>Profile</span>
        </a>

        <a
            href="../auth/logout.php"
            class="sidebar-link"
        >
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </nav>

</aside>

<div
    class="sidebar-overlay"
    id="sidebarOverlay"
></div>

<!-- ================================================================
     MAIN
================================================================ -->

<main class="main-content">

    <!-- Topbar -->
    <header class="topbar">

        <div class="d-flex align-items-center gap-3">

            <button
                type="button"
                class="mobile-menu-btn"
                id="mobileMenuBtn"
                aria-label="Open menu"
            >
                <i class="bi bi-list"></i>
            </button>

            <div>
                <div class="page-title-small">
                    Gallery Management
                </div>

                <div class="page-subtitle-small">
                    Manage school photos and events
                </div>
            </div>

        </div>

        <div class="admin-profile">

            <div class="text-end">

                <div class="admin-name">
                    <?= e($adminName) ?>
                </div>

                <div class="admin-role">
                    Administrator
                </div>

            </div>

            <div class="admin-avatar">
                <?= e($adminInitial) ?>
            </div>

        </div>

    </header>

    <!-- Page -->
    <div class="page-container">

        <!-- Page Header -->

        <div class="page-header">

            <div class="page-heading">

                <h1>
                    School Gallery
                </h1>

                <p>
                    Add and organize photos of school activities,
                    facilities, events, and achievements.
                </p>

            </div>

            <button
                type="button"
                class="btn btn-primary px-4"
                data-bs-toggle="modal"
                data-bs-target="#addPhotoModal"
            >
                <i class="bi bi-plus-lg me-2"></i>
                Add Photo
            </button>

        </div>

        <!-- Messages -->

        <?php if ($message !== ''): ?>

            <div
                class="alert alert-<?= e($messageType === 'success' ? 'success' : 'danger') ?> alert-dismissible fade show"
                role="alert"
            >

                <i
                    class="bi <?= $messageType === 'success'
                        ? 'bi-check-circle-fill'
                        : 'bi-exclamation-triangle-fill'
                    ?> me-2"
                ></i>

                <?= e($message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>

        <!-- Statistics -->

        <div class="row g-3">

            <div class="col-12 col-sm-6 col-lg-3">

                <div class="stat-card">

                    <div class="stat-icon">
                        <i class="bi bi-images"></i>
                    </div>

                    <div class="stat-number">
                        <?= number_format($totalPhotos) ?>
                    </div>

                    <div class="stat-label">
                        Total Photos
                    </div>

                </div>

            </div>

            <?php
            $statCategories = [
                'events' => [
                    'icon' => 'bi-calendar-event',
                    'label' => 'Events'
                ],
                'computer-lab' => [
                    'icon' => 'bi-pc-display',
                    'label' => 'Computer Lab'
                ],
                'library' => [
                    'icon' => 'bi-book',
                    'label' => 'Library'
                ]
            ];
            ?>

            <?php foreach ($statCategories as $key => $stat): ?>

                <div class="col-12 col-sm-6 col-lg-3">

                    <div class="stat-card">

                        <div class="stat-icon">
                            <i class="bi <?= e($stat['icon']) ?>"></i>
                        </div>

                        <div class="stat-number">
                            <?= number_format($categoryCounts[$key] ?? 0) ?>
                        </div>

                        <div class="stat-label">
                            <?= e($stat['label']) ?>
                        </div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

        <!-- Filters -->

        <div class="filter-card">

            <form
                method="GET"
                action="gallery.php"
            >

                <div class="row g-3 align-items-end">

                    <div class="col-12 col-md-5">

                        <label class="form-label">
                            Search
                        </label>

                        <div class="input-group">

                            <span class="input-group-text bg-white">
                                <i class="bi bi-search"></i>
                            </span>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                placeholder="Search photos..."
                                value="<?= e($search) ?>"
                            >

                        </div>

                    </div>

                    <div class="col-12 col-md-4">

                        <label class="form-label">
                            Category
                        </label>

                        <select
                            name="category"
                            class="form-select"
                        >

                            <option value="">
                                All Categories
                            </option>

                            <?php foreach ($categories as $key => $name): ?>

                                <option
                                    value="<?= e($key) ?>"
                                    <?= $filterCategory === $key ? 'selected' : '' ?>
                                >
                                    <?= e($name) ?>
                                    (<?= number_format($categoryCounts[$key] ?? 0) ?>)
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12 col-md-3">

                        <div class="d-flex gap-2">

                            <button
                                type="submit"
                                class="btn btn-primary flex-grow-1"
                            >
                                <i class="bi bi-search me-1"></i>
                                Filter
                            </button>

                            <a
                                href="gallery.php"
                                class="btn btn-outline-secondary"
                            >
                                <i class="bi bi-arrow-counterclockwise"></i>
                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </div>

        <!-- Gallery -->

        <?php if (empty($photos)): ?>

            <div class="empty-state">

                <div class="empty-icon">
                    <i class="bi bi-images"></i>
                </div>

                <h3>
                    No Photos Found
                </h3>

                <p>
                    <?php if ($search !== '' || $filterCategory !== ''): ?>

                        No photos match your current search or category filter.

                    <?php else: ?>

                        Your school gallery is empty. Add your first photo to get started.

                    <?php endif; ?>
                </p>

                <?php if ($search === '' && $filterCategory === ''): ?>

                    <button
                        type="button"
                        class="btn btn-primary"
                        data-bs-toggle="modal"
                        data-bs-target="#addPhotoModal"
                    >
                        <i class="bi bi-plus-lg me-2"></i>
                        Add First Photo
                    </button>

                <?php else: ?>

                    <a
                        href="gallery.php"
                        class="btn btn-outline-primary"
                    >
                        Clear Filter
                    </a>

                <?php endif; ?>

            </div>

        <?php else: ?>

            <div class="row g-4">

                <?php foreach ($photos as $photo): ?>

                    <?php

                    $imagePath = (string) $photo['image_path'];

                    $imageUrl = '../public/'
                        . ltrim($imagePath, '/\\');

                    $photoTitle = (string) $photo['title'];

                    $photoDescription = (string) (
                        $photo['description'] ?? ''
                    );

                    $photoCategory = (string) $photo['category'];

                    $photoCategoryName = galleryCategoryName(
                        $photoCategory
                    );

                    ?>

                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">

                        <div class="gallery-card">

                            <div
                                class="gallery-image-wrapper"
                                onclick="openImageModal(
                                    <?= htmlspecialchars(
                                        json_encode($imageUrl),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>,
                                    <?= htmlspecialchars(
                                        json_encode($photoTitle),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                )"
                            >

                                <img
                                    src="<?= e($imageUrl) ?>"
                                    alt="<?= e($photoTitle) ?>"
                                    class="gallery-image"
                                    loading="lazy"
                                    onerror="this.src='../public/logo.webp';"
                                >

                                <span class="gallery-category">
                                    <?= e($photoCategoryName) ?>
                                </span>

                            </div>

                            <div class="gallery-body">

                                <div class="gallery-title">
                                    <?= e($photoTitle) ?>
                                </div>

                                <div class="gallery-description">

                                    <?php if ($photoDescription !== ''): ?>

                                        <?= e(
                                            strlen($photoDescription) > 95
                                                ? substr($photoDescription, 0, 95) . '...'
                                                : $photoDescription
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            No description
                                        </span>

                                    <?php endif; ?>

                                </div>

                                <div class="gallery-meta">

                                    <span>
                                        <i class="bi bi-calendar3 me-1"></i>

                                        <?= e(
                                            formatEthiopianDate(
                                                $photo['created_at']
                                            )
                                        ) ?>

                                    </span>

                                    <div class="gallery-actions">

                                        <!-- Edit -->

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            title="Edit"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editPhotoModal"
                                            data-id="<?= (int) $photo['id'] ?>"
                                            data-title="<?= e($photo['title']) ?>"
                                            data-description="<?= e($photo['description'] ?? '') ?>"
                                            data-category="<?= e($photo['category']) ?>"
                                            data-image="<?= e($imageUrl) ?>"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <!-- Delete -->

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Delete"
                                            onclick="deletePhoto(
                                                <?= (int) $photo['id'] ?>,
                                                <?= htmlspecialchars(
                                                    json_encode($photoTitle),
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ) ?>
                                            )"
                                        >
                                            <i class="bi bi-trash"></i>
                                        </button>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</main>

<!-- ================================================================
     ADD PHOTO MODAL
================================================================ -->

<div
    class="modal fade"
    id="addPhotoModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered modal-lg">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">
                        Add Gallery Photo
                    </h5>

                    <small class="text-muted">
                        Upload a photo to the school gallery
                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form
                method="POST"
                action="gallery.php"
                enctype="multipart/form-data"
            >

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="add"
                    >

                    <div class="row g-3">

                        <div class="col-12">

                            <label class="form-label">
                                Photo Title
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                name="title"
                                class="form-control"
                                placeholder="Example: Grade 8 Science Laboratory"
                                maxlength="255"
                                required
                            >

                        </div>

                        <div class="col-12 col-md-6">

                            <label class="form-label">
                                Category
                                <span class="text-danger">*</span>
                            </label>

                            <select
                                name="category"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select Category
                                </option>

                                <?php foreach ($categories as $key => $name): ?>

                                    <option value="<?= e($key) ?>">
                                        <?= e($name) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-12 col-md-6">

                            <label class="form-label">
                                Image
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="file"
                                name="image"
                                id="addImage"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp"
                                required
                            >

                            <small class="text-muted">
                                JPG, PNG or WEBP — maximum 5 MB
                            </small>

                        </div>

                        <div class="col-12">

                            <img
                                id="addImagePreview"
                                class="image-preview"
                                alt="Image Preview"
                            >

                        </div>

                        <div class="col-12">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea
                                name="description"
                                class="form-control"
                                placeholder="Optional description..."
                            ></textarea>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-upload me-2"></i>
                        Upload Photo
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- ================================================================
     EDIT PHOTO MODAL
================================================================ -->

<div
    class="modal fade"
    id="editPhotoModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered modal-lg">

        <div class="modal-content">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">
                        Edit Gallery Photo
                    </h5>

                    <small class="text-muted">
                        Update photo information
                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <form
                method="POST"
                action="gallery.php"
                enctype="multipart/form-data"
            >

                <div class="modal-body">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="action"
                        value="edit"
                    >

                    <input
                        type="hidden"
                        name="id"
                        id="editPhotoId"
                    >

                    <div class="row g-3">

                        <div class="col-12">

                            <label class="form-label">
                                Photo Title
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                name="title"
                                id="editPhotoTitle"
                                class="form-control"
                                maxlength="255"
                                required
                            >

                        </div>

                        <div class="col-12 col-md-6">

                            <label class="form-label">
                                Category
                                <span class="text-danger">*</span>
                            </label>

                            <select
                                name="category"
                                id="editPhotoCategory"
                                class="form-select"
                                required
                            >

                                <?php foreach ($categories as $key => $name): ?>

                                    <option value="<?= e($key) ?>">
                                        <?= e($name) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="col-12 col-md-6">

                            <label class="form-label">
                                Replace Image
                            </label>

                            <input
                                type="file"
                                name="image"
                                id="editImage"
                                class="form-control"
                                accept="image/jpeg,image/png,image/webp"
                            >

                            <small class="text-muted">
                                Leave empty to keep the current image.
                            </small>

                        </div>

                        <div class="col-12">

                            <img
                                id="editImagePreview"
                                class="image-preview"
                                alt="Current Image"
                            >

                        </div>

                        <div class="col-12">

                            <label class="form-label">
                                Description
                            </label>

                            <textarea
                                name="description"
                                id="editPhotoDescription"
                                class="form-control"
                            ></textarea>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-check-lg me-2"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<!-- ================================================================
     IMAGE VIEW MODAL
================================================================ -->

<div
    class="modal fade"
    id="imageViewModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-xl modal-dialog-centered">

        <div class="modal-content bg-dark">

            <div class="modal-header border-0">

                <h5
                    class="modal-title text-white"
                    id="imageViewTitle"
                >
                    Photo
                </h5>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body text-center p-2">

                <img
                    id="imageView"
                    src=""
                    alt=""
                    style="
                        max-width:100%;
                        max-height:75vh;
                        object-fit:contain;
                        border-radius:10px;
                    "
                >

            </div>

        </div>

    </div>

</div>

<!-- ================================================================
     DELETE FORM
================================================================ -->

<form
    method="POST"
    action="gallery.php"
    id="deletePhotoForm"
    style="display:none;"
>

    <input
        type="hidden"
        name="csrf_token"
        value="<?= e($csrfToken) ?>"
    >

    <input
        type="hidden"
        name="action"
        value="delete"
    >

    <input
        type="hidden"
        name="id"
        id="deletePhotoId"
    >

</form>

<!-- Bootstrap -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<script>

/*
|--------------------------------------------------------------------------
| Mobile Sidebar
|--------------------------------------------------------------------------
*/

const sidebar = document.getElementById('sidebar');
const sidebarOverlay = document.getElementById('sidebarOverlay');
const mobileMenuBtn = document.getElementById('mobileMenuBtn');

function openSidebar() {
    if (sidebar) {
        sidebar.classList.add('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.add('show');
    }
}

function closeSidebar() {
    if (sidebar) {
        sidebar.classList.remove('show');
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.remove('show');
    }
}

if (mobileMenuBtn) {
    mobileMenuBtn.addEventListener('click', openSidebar);
}

if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeSidebar);
}

/*
|--------------------------------------------------------------------------
| Add Image Preview
|--------------------------------------------------------------------------
*/

const addImage = document.getElementById('addImage');
const addImagePreview = document.getElementById('addImagePreview');

if (addImage) {

    addImage.addEventListener('change', function () {

        const file = this.files && this.files[0];

        if (!file) {

            addImagePreview.style.display = 'none';
            addImagePreview.removeAttribute('src');

            return;
        }

        if (file.size > 5 * 1024 * 1024) {

            alert('Image size must not exceed 5 MB.');

            this.value = '';

            addImagePreview.style.display = 'none';

            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {

            addImagePreview.src = event.target.result;

            addImagePreview.style.display = 'block';
        };

        reader.readAsDataURL(file);

    });
}

/*
|--------------------------------------------------------------------------
| Edit Modal
|--------------------------------------------------------------------------
*/

const editPhotoModal = document.getElementById(
    'editPhotoModal'
);

if (editPhotoModal) {

    editPhotoModal.addEventListener(
        'show.bs.modal',
        function (event) {

            const button = event.relatedTarget;

            if (!button) {
                return;
            }

            const id = button.getAttribute('data-id') || '';
            const title = button.getAttribute('data-title') || '';
            const description =
                button.getAttribute('data-description') || '';
            const category =
                button.getAttribute('data-category') || '';
            const image =
                button.getAttribute('data-image') || '';

            document.getElementById(
                'editPhotoId'
            ).value = id;

            document.getElementById(
                'editPhotoTitle'
            ).value = title;

            document.getElementById(
                'editPhotoDescription'
            ).value = description;

            document.getElementById(
                'editPhotoCategory'
            ).value = category;

            const preview = document.getElementById(
                'editImagePreview'
            );

            if (image !== '') {

                preview.src = image;
                preview.style.display = 'block';

            } else {

                preview.removeAttribute('src');
                preview.style.display = 'none';
            }

            const editImage = document.getElementById(
                'editImage'
            );

            if (editImage) {
                editImage.value = '';
            }

        }
    );
}

/*
|--------------------------------------------------------------------------
| Edit Image Preview
|--------------------------------------------------------------------------
*/

const editImage = document.getElementById('editImage');
const editImagePreview = document.getElementById(
    'editImagePreview'
);

if (editImage) {

    editImage.addEventListener('change', function () {

        const file = this.files && this.files[0];

        if (!file) {
            return;
        }

        if (file.size > 5 * 1024 * 1024) {

            alert('Image size must not exceed 5 MB.');

            this.value = '';

            return;
        }

        const reader = new FileReader();

        reader.onload = function (event) {

            editImagePreview.src =
                event.target.result;

            editImagePreview.style.display =
                'block';
        };

        reader.readAsDataURL(file);

    });
}

/*
|--------------------------------------------------------------------------
| Delete Photo
|--------------------------------------------------------------------------
*/

function deletePhoto(id, title) {

    const confirmed = confirm(
        'Are you sure you want to delete "' +
        title +
        '"?\n\nThis action cannot be undone.'
    );

    if (!confirmed) {
        return;
    }

    document.getElementById(
        'deletePhotoId'
    ).value = id;

    document.getElementById(
        'deletePhotoForm'
    ).submit();
}

/*
|--------------------------------------------------------------------------
| Image Viewer
|--------------------------------------------------------------------------
*/

function openImageModal(imageUrl, title) {

    const imageElement = document.getElementById(
        'imageView'
    );

    const titleElement = document.getElementById(
        'imageViewTitle'
    );

    imageElement.src = imageUrl;
    imageElement.alt = title;

    titleElement.textContent = title;

    const modalElement = document.getElementById(
        'imageViewModal'
    );

    const modal = bootstrap.Modal.getOrCreateInstance(
        modalElement
    );

    modal.show();
}

</script>

</body>
</html>