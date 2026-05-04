<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Helpers/Security.php';
require_once __DIR__ . '/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin');
    exit;
}

if (!validate_csrf()) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$name = $_POST['name'] ?? '';

if (empty($name) || !valid_bucket_name($name)) {
    exit('Invalid bucket name.');
}

if (!get_bucket($name)) {
    exit('Bucket not found.');
}

// Remove from buckets.json
try {
    $buckets = load_buckets();
    $new = array_filter($buckets, fn($b) => $b['name'] !== $name);
    save_buckets(array_values($new));
} catch (Exception $e) {
    error_log("Error updating buckets.json: " . $e->getMessage());
    exit('Error deleting bucket from metadata.');
}

// Recursively delete bucket folder and all contents
$bucket_dir = BASE_DIR . $name;
if (is_dir($bucket_dir)) {
    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($bucket_dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            if ($file->isDir()) {
                if (!@rmdir($file->getRealPath())) {
                    error_log("Failed to delete directory: " . $file->getRealPath());
                }
            } else {
                if (!@unlink($file->getRealPath())) {
                    error_log("Failed to delete file: " . $file->getRealPath());
                }
            }
        }
        if (!@rmdir($bucket_dir)) {
            error_log("Failed to delete bucket directory: $bucket_dir");
        }
    } catch (Exception $e) {
        error_log("Error deleting bucket directory: " . $e->getMessage());
        exit('Error deleting bucket files.');
    }
}

// Log bucket deletion
audit_log('BUCKET_DELETED', [
    'bucket' => $name
]);

header('Location: /admin');
exit;
