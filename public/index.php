<?php

// For PHP built-in server, route all requests through this file
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
} else {
    // For Apache with mod_rewrite
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // Remove script name if present (e.g., if accessed as /index.php/admin)
    $script_name = basename($_SERVER['SCRIPT_NAME']);
    if (strpos($path, $script_name) === 1) {
        $path = substr($path, strlen('/' . $script_name));
    }
    if (empty($path)) {
        $path = '/';
    }
}

// Define allowed routes (whitelist) - sorted by length (longest first)
$routes = [
    '/admin/create' => __DIR__ . '/../app/Admin/CreateBucket.php',
    '/admin/delete' => __DIR__ . '/../app/Admin/DeleteBucket.php',
    '/api/upload' => __DIR__ . '/../app/Api/Upload.php',
    '/api/file'   => __DIR__ . '/../app/Api/File.php',
    '/api/delete' => __DIR__ . '/../app/Api/Delete.php',
    '/admin'      => __DIR__ . '/../app/Admin/Dashboard.php',
    '/'           => __DIR__ . '/../app/Admin/Dashboard.php',
];

// Find matching route (exact match or followed by query string)
$matched = false;
foreach ($routes as $route => $handler) {
    if ($path === $route || strpos($path, $route . '?') === 0 || strpos($path, $route . '/') === 0) {
        require_once $handler;
        $matched = true;
        break;
    }
}

if (!$matched) {
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Route not found']);
}
