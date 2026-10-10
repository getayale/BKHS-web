<?php

declare(strict_types=1);

require_once '../../../config/database.php';

$id = 2;

$stmt = $conn->prepare(
    "SELECT file_path, original_file_name, file_type, file_size
     FROM homework_submissions
     WHERE id = ?
     LIMIT 1"
);

$stmt->bind_param('i', $id);
$stmt->execute();

$result = $stmt->get_result();
$row = $result->fetch_assoc();

$stmt->close();

if (!$row) {
    die('Submission not found.');
}

$projectRoot = dirname(__DIR__, 3);

$relativePath = ltrim(
    (string) $row['file_path'],
    '/\\'
);

$physicalPath = $projectRoot
    . DIRECTORY_SEPARATOR
    . str_replace(
        ['/', '\\'],
        DIRECTORY_SEPARATOR,
        $relativePath
    );

echo '<pre>';

echo "Submission ID: ";
echo $id;
echo "\n\n";

echo "Database file_path:\n";
echo $row['file_path'];
echo "\n\n";

echo "Original filename:\n";
echo $row['original_file_name'];
echo "\n\n";

echo "Database file type:\n";
echo $row['file_type'];
echo "\n\n";

echo "Database file size:\n";
echo $row['file_size'] . " bytes";
echo "\n\n";

echo "Project root:\n";
echo $projectRoot;
echo "\n\n";

echo "Physical path:\n";
echo $physicalPath;
echo "\n\n";

echo "File exists:\n";
echo is_file($physicalPath) ? 'YES' : 'NO';
echo "\n\n";

echo "Real path:\n";
echo realpath($physicalPath) ?: 'NOT FOUND';
echo "\n\n";

if (is_file($physicalPath)) {
    echo "Actual file size:\n";
    echo filesize($physicalPath) . " bytes";
    echo "\n\n";

    echo "Readable:\n";
    echo is_readable($physicalPath) ? 'YES' : 'NO';
}

echo '</pre>';