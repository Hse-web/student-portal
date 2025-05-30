<?php
// File: config/helpers/csrf.php

/**
 * Generate + return a one‐time CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate and discard a token.
 */
function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        unset($_SESSION['csrf_token']);
    }
    return $valid;
}
