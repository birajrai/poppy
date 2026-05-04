<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Helpers/Security.php';

header('Content-Type: application/json');

$bucket = $_GET['bucket'] ?? '';
$key = $_GET['key'] ?? '';

if (!valid_bucket($bucket) || !auth_ok($bucket, $key)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

if (!isset($_FILES['file'])) {
    http_response_code(400);
    exit(json_encode(['error' => 'No file uploaded']));
}

$file = $_FILES['file'];
$validation = validate_upload($file);
if ($validation !== true) {
    http_response_code(400);
    exit(json_encode(['error' => $validation]));
}

$mime = detect_mime($file['tmp_name']);
$ext = ALLOWED_TYPES[$mime];

// Generate unique filename with sharding
$hash = bin2hex(random_bytes(16));
$shard = substr($hash, 0, 2);

$dir = bucket_path($bucket) . $shard . '/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$filename = "$hash.$ext";
$full_path = $shard . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
    http_response_code(500);
    exit(json_encode(['error' => 'Failed to save file']));
}

// Add entry to per-bucket files.json
add_file_entry($bucket, $full_path, filesize($dir . $filename), $mime);

$url = BASE_URL . "?bucket=$bucket&f=$full_path";

echo json_encode([
    'success' => true,
    'file' => $full_path,
    'url' => $url
]);
