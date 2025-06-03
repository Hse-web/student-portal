<?php
// File: config/session.php
// ---------------------------
// Starts/resumes the session and provides CSRF‐helper functions.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or return the existing CSRF token.
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate a given CSRF token against the session.
 */
function verify_csrf_token(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals((string) $_SESSION['csrf_token'], (string) $token);
    if ($valid) {
        // Optionally, you can unset it here to force one‐time use:
        // unset($_SESSION['csrf_token']);
    }
    return $valid;
}

/**
 * Simple login throttling (5 fails per hour).
 */
function throttle_login(string $username): bool {
    $key = 'login_fails_' . sha1($username);
    $entry = $_SESSION[$key] ?? ['count' => 0, 'firstFail' => time()];
    if (time() - $entry['firstFail'] > 3600) {
        $entry = ['count' => 0, 'firstFail' => time()];
    }
    if ($entry['count'] >= 5) {
        return false;
    }
    $_SESSION[$key] = $entry;
    return true;
}

function log_failed_login(string $username): void {
    $key = 'login_fails_' . sha1($username);
    $entry = $_SESSION[$key] ?? ['count' => 0, 'firstFail' => time()];
    $entry['count']++;
    $_SESSION[$key] = $entry;
}
