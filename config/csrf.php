<?php

function generateCsrfToken() {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    // Generate a random token
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

function verifyCsrfToken($token) {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    // Check if the token is valid
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

?>