<?php

// Validate bucket name (strict alphanumeric + underscore/hyphen)
function valid_bucket_name($name) {
    return preg_match('/^[a-zA-Z0-9_-]+$/', $name) === 1;
}

// Sanitize file path to prevent traversal
function sanitize_path($path) {
    // Decode URL encoding to catch encoded traversal attempts
    $path = urldecode($path);
    $path = str_replace(['..', '\\', '//'], '', $path);
    return ltrim($path, '/');
}

// Validate file path stays within bucket directory
function valid_file_path($bucket, $file_path) {
    $bucket_dir = bucket_path($bucket);
    $full_path = $bucket_dir . ltrim($file_path, '/');

    // Ensure bucket directory exists
    if (!is_dir($bucket_dir)) return false;

    $real_bucket = realpath($bucket_dir);

    // For existing files, verify realpath
    if (file_exists($full_path)) {
        $real_file = realpath($full_path);
        return $real_file && strpos($real_file, $real_bucket) === 0;
    }

    // For new files (upload), just verify the path doesn't contain traversal
    $normalized = $bucket_dir . ltrim(sanitize_path($file_path), '/');
    return strpos($normalized, $real_bucket) === 0;
}

// Note: validate_upload() is defined in config.php, not here
