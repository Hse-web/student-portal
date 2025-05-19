<?php
// File: config/session.php
// ----------------------------------
// Secure session setup + CSRF and login-throttle helpers

// Session cookie parameters (adjust secure/httponly/samesite as needed)
// session_set_cookie_params([
//     'lifetime' => 0,
//     'path'     => '/',
//     'domain'   => $_SERVER['HTTP_HOST'],
//     'secure'   => true,
//     'httponly' => true,
// ]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Enforce that a user is logged in with a given role.
 */
function require_role(string $role) {
    if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: /login.php');
        exit;
    }
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
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        // Invalidate token after first use
        unset($_SESSION['csrf_token']);
    }
    return $valid;
}

/**
 * Simple login throttling: max 5 failed attempts per hour per username.
 */
function throttle_login(string $username): bool {
    $key = 'login_fails_' . sha1($username);
    $entry = $_SESSION[$key] ?? ['count' => 0, 'firstFail' => time()];

    // Reset counter after 1 hour
    if (time() - $entry['firstFail'] > 3600) {
        $entry = ['count' => 0, 'firstFail' => time()];
    }
    
    // If 5 or more fails, block
    if ($entry['count'] >= 5) {
        return false;
    }
    
    // Allow
    $_SESSION[$key] = $entry;
    return true;
}

/**
 * Record a failed login attempt for a username.
 */
function log_failed_login(string $username): void {
    $key = 'login_fails_' . sha1($username);
    $entry = $_SESSION[$key] ?? ['count' => 0, 'firstFail' => time()];
    $entry['count']++;
    $_SESSION[$key] = $entry;
}
