<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Helpers/Security.php';

// Validate HTTP method (GET or HEAD allowed)
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'])) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$bucket = trim($_GET['bucket'] ?? '');
$file = trim($_GET['f'] ?? '');

// Validate required parameters
if (empty($bucket) || empty($file)) {
    http_response_code(400);
    exit;
}

if (!valid_bucket($bucket)) {
    http_response_code(404);
    exit;
}

$file = sanitize_path($file);
if (!valid_file_path($bucket, $file)) {
    http_response_code(403);
    exit;
}

$path = bucket_path($bucket) . $file;
if (!file_exists($path)) {
    http_response_code(404);
    exit;
}

// Detect MIME and validate against whitelist
$f = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($f, $path);
finfo_close($f);

// Validate MIME type against whitelist
if (!isset(ALLOWED_TYPES[$mime])) {
    http_response_code(403);
    error_log("Attempted to serve non-whitelisted MIME type: $mime for file: $path");
    exit;
}

// Set caching and security headers
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=31536000, immutable');
header('ETag: "' . md5_file($path) . '"');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Set Content-Disposition for safe display
if (in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])) {
    header('Content-Disposition: inline');
}

// Don't send body for HEAD requests
if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    exit;
}

readfile($path);
