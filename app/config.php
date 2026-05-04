<?php

// Manual .env loader (no Composer dependencies)
function load_env($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') === false || strpos($line, '#') === 0) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (preg_match('/^"(.*)"$/', $value, $m)) $value = $m[1];
        elseif (preg_match("/^'(.*)'$/", $value, $m)) $value = $m[1];
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Load .env from project root
load_env(__DIR__ . '/../.env');

// Constants (hardcoded, not from .env)
define('BASE_DIR', __DIR__ . '/../storage/buckets/');
define('BUCKET_FILE', __DIR__ . '/../storage/buckets.json');
define('MAX_SIZE', 10485760); // 10MB
define('ALLOWED_TYPES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf'
]);

// From .env (customizable per deployment)
define('BASE_URL', rtrim(getenv('URL') ?: 'http://localhost:3060', '/') . '/api/file');
define('ADMIN_USER', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASS', getenv('ADMIN_PASS') ?: '');
define('CSRF_SECRET', getenv('CSRF_SECRET') ?: '');

// Load buckets from global JSON
function load_buckets() {
    if (!file_exists(BUCKET_FILE)) return [];
    $data = json_decode(file_get_contents(BUCKET_FILE), true);
    return is_array($data) ? $data : [];
}

// Save buckets to global JSON
function save_buckets($data) {
    file_put_contents(BUCKET_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Get bucket by name
function get_bucket($name) {
    foreach (load_buckets() as $b) {
        if ($b['name'] === $name) return $b;
    }
    return null;
}

// Check if bucket exists
function valid_bucket($bucket) {
    return get_bucket($bucket) !== null;
}

// Validate API key for bucket (timing-safe)
function auth_ok($bucket, $key) {
    $b = get_bucket($bucket);
    return $b && password_verify($key, $b['key']);
}

// Admin auth
function admin_auth() {
    if (!isset($_SERVER['PHP_AUTH_USER']) ||
        $_SERVER['PHP_AUTH_USER'] !== ADMIN_USER) {
        header('WWW-Authenticate: Basic realm="Poppy Storage Admin"');
        header('HTTP/1.0 401 Unauthorized');
        exit('Unauthorized');
    }

    $input_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    // Support both plaintext and bcrypt hashed passwords
    if (ADMIN_PASS === '') {
        header('WWW-Authenticate: Basic realm="Poppy Storage Admin"');
        header('HTTP/1.0 401 Unauthorized');
        exit('Unauthorized');
    }

    // Check if ADMIN_PASS is a bcrypt hash
    if (password_get_info(ADMIN_PASS)['algo'] !== null) {
        if (!password_verify($input_pass, ADMIN_PASS)) {
            header('WWW-Authenticate: Basic realm="Poppy Storage Admin"');
            header('HTTP/1.0 401 Unauthorized');
            exit('Unauthorized');
        }
    } else {
        // Plaintext comparison
        if (!hash_equals(ADMIN_PASS, $input_pass)) {
            header('WWW-Authenticate: Basic realm="Poppy Storage Admin"');
            header('HTTP/1.0 401 Unauthorized');
            exit('Unauthorized');
        }
    }
}

// CSRF token generation
function csrf_token() {
    return hash_hmac('sha256', 'poppy_admin', CSRF_SECRET);
}

function validate_csrf() {
    $token = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
    return hash_equals(csrf_token(), $token);
}

// Get bucket storage path
function bucket_path($bucket) {
    return BASE_DIR . $bucket . '/';
}

// Detect MIME type using finfo
function detect_mime($tmp) {
    $f = finfo_open(FILEINFO_MIME_TYPE);
    $m = finfo_file($f, $tmp);
    finfo_close($f);
    return $m;
}

// Validate uploaded file
function validate_upload($file) {
    if ($file['size'] > MAX_SIZE) return 'File too large (max 10MB)';
    $mime = detect_mime($file['tmp_name']);
    if (!isset(ALLOWED_TYPES[$mime])) return 'Invalid file type (only JPG, PNG, WebP, PDF allowed)';
    return true;
}

// Load .env.example if .env doesn't exist (for defaults)
if (!file_exists(__DIR__ . '/../.env')) {
    load_env(__DIR__ . '/../.env.example');
}

// Load per-bucket files.json
function load_files($bucket) {
    $path = bucket_path($bucket) . 'files.json';
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

// Save per-bucket files.json
function save_files($bucket, $data) {
    $path = bucket_path($bucket) . 'files.json';
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// Add file entry to per-bucket files.json
function add_file_entry($bucket, $path, $size, $mime) {
    $files = load_files($bucket);
    $files[] = [
        'path' => $path,
        'size' => $size,
        'mime' => $mime,
        'uploaded_at' => date('Y-m-d H:i:s')
    ];
    save_files($bucket, $files);
}

// Remove file entry from per-bucket files.json
function remove_file_entry($bucket, $file_path) {
    $files = load_files($bucket);
    $new = array_filter($files, fn($f) => $f['path'] !== $file_path);
    save_files($bucket, array_values($new));
}

// Calculate total bucket size from files.json
function calculate_bucket_size($bucket) {
    $files = load_files($bucket);
    $total = 0;
    foreach ($files as $f) $total += $f['size'];
    return $total;
}

// Format bytes to human-readable size
function format_size($bytes) {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
