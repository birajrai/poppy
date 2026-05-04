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

// Load buckets from global JSON with error handling
function load_buckets() {
    if (!file_exists(BUCKET_FILE)) return [];

    $content = @file_get_contents(BUCKET_FILE);
    if ($content === false) {
        error_log("Failed to read buckets file: " . BUCKET_FILE);
        return [];
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON in buckets file: " . json_last_error_msg());
        return [];
    }

    return is_array($data) ? $data : [];
}

// Save buckets to global JSON with error handling
function save_buckets($data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON encode error: " . json_last_error_msg());
        throw new Exception("Failed to encode bucket data");
    }

    if (file_put_contents(BUCKET_FILE, $json) === false) {
        error_log("Failed to write buckets file: " . BUCKET_FILE);
        throw new Exception("Failed to save bucket data");
    }
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

// Admin auth with timing-safe comparison
function admin_auth() {
    $provided_user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $provided_pass = $_SERVER['PHP_AUTH_PW'] ?? '';

    // Check if credentials provided
    if (empty($provided_user) || empty($provided_pass) || empty(ADMIN_PASS)) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Poppy Storage Admin"');
        exit('Unauthorized');
    }

    // Timing-safe username comparison
    $username_ok = hash_equals(ADMIN_USER, $provided_user);

    // Timing-safe password comparison (always attempt verify to prevent timing leak)
    // Check if ADMIN_PASS is a bcrypt hash
    if (password_get_info(ADMIN_PASS)['algo'] !== null) {
        // Hash comparison (bcrypt)
        $password_ok = password_verify($provided_pass, ADMIN_PASS);
    } else {
        // Plaintext comparison (timing-safe)
        $password_ok = hash_equals(ADMIN_PASS, $provided_pass);
    }

    // Both must be correct
    if (!$username_ok || !$password_ok) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Poppy Storage Admin"');
        exit('Unauthorized');
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

// Load per-bucket files.json with error handling
function load_files($bucket) {
    $path = bucket_path($bucket) . 'files.json';
    if (!file_exists($path)) return [];

    $content = @file_get_contents($path);
    if ($content === false) {
        error_log("Failed to read files.json for bucket: $bucket");
        return [];
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("Invalid JSON in files.json for bucket $bucket: " . json_last_error_msg());
        return [];
    }

    return is_array($data) ? $data : [];
}

// Save per-bucket files.json with error handling
function save_files($bucket, $data) {
    $path = bucket_path($bucket) . 'files.json';
    
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON encode error for bucket $bucket: " . json_last_error_msg());
        throw new Exception("Failed to encode file data");
    }

    if (file_put_contents($path, $json) === false) {
        error_log("Failed to write files.json for bucket: $bucket");
        throw new Exception("Failed to save file data");
    }
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

// Rate limiting with file-based counter
function rate_limit($key, $max_requests = 100, $window_seconds = 3600) {
    $cache_file = __DIR__ . '/../storage/.rate_limit_' . md5($key);
    $now = time();

    if (file_exists($cache_file)) {
        $data = @json_decode(@file_get_contents($cache_file), true);
        if (is_array($data)) {
            $requests = $data['count'] ?? 0;
            $timestamp = $data['time'] ?? 0;

            // Outside time window - reset counter
            if ($now - $timestamp > $window_seconds) {
                @file_put_contents($cache_file, json_encode(['count' => 1, 'time' => $now]));
                return ['allowed' => true, 'remaining' => $max_requests - 1, 'reset' => $now + $window_seconds];
            }

            // Within time window
            if ($requests >= $max_requests) {
                return ['allowed' => false, 'remaining' => 0, 'reset' => $timestamp + $window_seconds];
            }

            // Increment counter
            @file_put_contents($cache_file, json_encode(['count' => $requests + 1, 'time' => $timestamp]));
            return ['allowed' => true, 'remaining' => $max_requests - ($requests + 1), 'reset' => $timestamp + $window_seconds];
        }
    }

    // First request in window
    @file_put_contents($cache_file, json_encode(['count' => 1, 'time' => $now]));
    return ['allowed' => true, 'remaining' => $max_requests - 1, 'reset' => $now + $window_seconds];
}

// Audit logging for security events
function audit_log($action, $details = []) {
    $log_file = __DIR__ . '/../storage/audit.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
    $user = $_SERVER['PHP_AUTH_USER'] ?? $_GET['bucket'] ?? 'ANONYMOUS';

    $entry = "[$timestamp] [$user] [$ip] $action | " . json_encode($details);
    @error_log($entry . "\n", 3, $log_file);
}
