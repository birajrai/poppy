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

$name = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['name'] ?? '');

if (empty($name) || !valid_bucket_name($name)) {
    exit('Invalid bucket name. Use only letters, numbers, underscores, and hyphens.');
}

// Check if bucket already exists
if (get_bucket($name)) {
    exit('Bucket already exists.');
}

// Generate API key (plaintext - shown only once)
$plain_key = bin2hex(random_bytes(16));

// Hash key for storage (BCRYPT)
$hashed_key = password_hash($plain_key, PASSWORD_BCRYPT);

// Add to buckets.json
$buckets = load_buckets();
$buckets[] = ['name' => $name, 'key' => $hashed_key];
save_buckets($buckets);

// Create bucket directory
mkdir(BASE_DIR . $name, 0755, true);

// Create empty files.json for the bucket
save_files($name, []);

// Log bucket creation
audit_log('BUCKET_CREATED', [
    'bucket' => $name
]);

// Show success with plaintext key (only time it's shown)
?>
<!DOCTYPE html>
<html>
<head><title>Bucket Created</title></head>
<body style="font-family: Arial; max-width: 600px; margin: 40px auto; padding: 20px;">
    <h2>Bucket Created Successfully</h2>
    <p><strong>Bucket Name:</strong> <?= htmlspecialchars($name) ?></p>
    <p><strong>API Key (save this, it won't be shown again):</strong></p>
    <p style="background: #f4f4f4; padding: 10px; font-family: monospace; word-break: break-all;"><?= htmlspecialchars($plain_key) ?></p>
    <p><a href="/admin">Back to Dashboard</a></p>
</body>
</html>
