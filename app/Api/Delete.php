<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Helpers/Security.php';

header('Content-Type: application/json');

// Validate HTTP method (POST or DELETE allowed)
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'])) {
    http_response_code(405);
    header('Allow: POST, DELETE');
    exit(json_encode(['error' => 'POST or DELETE method required']));
}

// Rate limiting per IP (50 deletes per hour)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rate_limit = rate_limit("delete_$ip", 50, 3600);
if (!$rate_limit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rate_limit['reset']);
    exit(json_encode(['error' => 'Rate limit exceeded. Try again later.']));
}

$bucket = $_GET['bucket'] ?? '';
$file = $_GET['f'] ?? '';
$key = $_GET['key'] ?? '';

if (!valid_bucket($bucket) || !auth_ok($bucket, $key)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$file = sanitize_path($file);
if (!valid_file_path($bucket, $file)) {
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid file path']));
}

$path = bucket_path($bucket) . $file;
if (!file_exists($path)) {
    http_response_code(404);
    exit(json_encode(['error' => 'File not found']));
}

try {
    // Delete file
    if (!@unlink($path)) {
        http_response_code(500);
        error_log("Failed to delete file: $path");
        exit(json_encode(['error' => 'Failed to delete file']));
    }

    // Remove entry from files.json
    remove_file_entry($bucket, $file);

    // Log delete action
    audit_log('FILE_DELETED', [
        'bucket' => $bucket,
        'file' => $file
    ]);

    // Clean up empty shard directory (safe - check before and after)
    $shard_dir = dirname($path);
    if (is_dir($shard_dir)) {
        $files = @scandir($shard_dir);
        // scandir returns at least . and .. entries (2 entries)
        if (is_array($files) && count($files) <= 2) {
            @rmdir($shard_dir);
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("Delete error: " . $e->getMessage());
    exit(json_encode(['error' => 'Failed to delete file']));
}

echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
