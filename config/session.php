<?php
// File: config/session.php
// Session start + auth & throttling helpers

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Require a given role (e.g. 'admin').
 */
function require_role(string $role) {
    if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Simple login‐throttle: max 5 fails / hour.
 */
function throttle_login(string $username): bool {
    $key   = 'login_fails_' . sha1($username);
    $entry = $_SESSION[$key] ?? ['count'=>0,'firstFail'=>time()];
    if (time() - $entry['firstFail'] > 3600) {
        $entry = ['count'=>0,'firstFail'=>time()];
    }
    if ($entry['count'] >= 5) {
        return false;
    }
    $_SESSION[$key] = $entry;
    return true;
}

/**
 * Record a failed login attempt.
 */
function log_failed_login(string $username): void {
    $key   = 'login_fails_' . sha1($username);
    $entry = $_SESSION[$key] ?? ['count'=>0,'firstFail'=>time()];
    $entry['count']++;
    $_SESSION[$key] = $entry;
}
