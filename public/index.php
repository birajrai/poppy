<?php

$request_uri = $_SERVER['REQUEST_URI'];
$script_name = $_SERVER['SCRIPT_NAME'];

// Remove script name from URI if present
$path = substr($request_uri, strlen(dirname($script_name)));

// Remove query string
$path = parse_url($path, PHP_URL_PATH);

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
