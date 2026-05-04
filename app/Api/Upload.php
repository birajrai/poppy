<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Helpers/Security.php';

header('Content-Type: application/json');

// Validate HTTP method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit(json_encode(['error' => 'POST method required']));
}

// Rate limiting per IP (100 uploads per hour)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rate_limit = rate_limit("upload_$ip", 100, 3600);
if (!$rate_limit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rate_limit['reset']);
    exit(json_encode(['error' => 'Rate limit exceeded. Try again later.']));
}

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

// Generate unique filename with sharding (use content hash instead of random)
$hash = hash_file('sha256', $file['tmp_name']);
$shard = substr($hash, 0, 2);
$filename = substr($hash, 2) . '.' . $ext;
$full_path = $shard . '/' . $filename;

$dir = bucket_path($bucket) . $shard . '/';
if (!is_dir($dir)) {
    if (!@mkdir($dir, 0755, true)) {
        http_response_code(500);
        error_log("Failed to create shard directory: $dir");
        exit(json_encode(['error' => 'Failed to save file']));
    }
}

try {
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        http_response_code(500);
        error_log("Failed to move uploaded file to: " . $dir . $filename);
        exit(json_encode(['error' => 'Failed to save file']));
    }

    // Verify file was actually written
    if (!file_exists($dir . $filename)) {
        http_response_code(500);
        error_log("File not found after move_uploaded_file: " . $dir . $filename);
        exit(json_encode(['error' => 'Failed to save file']));
    }

    $actual_size = filesize($dir . $filename);
    if ($actual_size === false) {
        http_response_code(500);
        error_log("Failed to get file size: " . $dir . $filename);
        exit(json_encode(['error' => 'Failed to save file']));
    }

    // Add entry to per-bucket files.json
    add_file_entry($bucket, $full_path, $actual_size, $mime);

    // Log upload action
    audit_log('FILE_UPLOADED', [
        'bucket' => $bucket,
        'file' => $full_path,
        'size' => $actual_size,
        'mime' => $mime
    ]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Upload error: " . $e->getMessage());
    exit(json_encode(['error' => 'Failed to save file']));
}

$url = BASE_URL . "?" . http_build_query(['bucket' => $bucket, 'f' => $full_path]);

echo json_encode([
    'success' => true,
    'file' => $full_path,
    'url' => $url
]);
