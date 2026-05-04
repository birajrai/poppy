<?php

require_once __DIR__ . '/../config.php';

admin_auth();

// CSRF token generation
function csrf_token() {
    $secret = CSRF_SECRET ?: '';
    return hash_hmac('sha256', 'poppy_admin', $secret);
}

function validate_csrf() {
    $token = $_POST['_csrf'] ?? $_GET['_csrf'] ?? '';
    return hash_equals(csrf_token(), $token);
}
