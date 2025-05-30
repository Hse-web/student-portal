<?php
// File: config/bootstrap.php
// Master include—pull in everything

// 1) Composer autoload (if you have vendor/)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// 2) Start session + env
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 3) DB connection
require_once __DIR__ . '/db.php';

// 4) Helpers
require_once __DIR__ . '/helpers/csrf.php';
require_once __DIR__ . '/helpers/audit.php';

// 5) Session + auth + throttle
require_once __DIR__ . '/session.php';
require_once __DIR__.'/config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $user   = trim($_POST['username'] ?? '');
  $pass   = $_POST['password'] ?? '';

  // 1) Rate‐limit check
  if (! throttle_login($user)) {
    $error = 'Too many login attempts. Try again later.';
  } else {
    // 2) Fetch user record safely
    $stmt = $conn->prepare("SELECT id, password_hash, role FROM users WHERE username=? LIMIT 1");
    $stmt->bind_param('s',$user);
    $stmt->execute();
    $stmt->bind_result($id,$hash,$role);
    if ($stmt->fetch() && password_verify($pass,$hash)) {
      // 3) Success!
      session_regenerate_id(true);
      $_SESSION['logged_in'] = true;
      $_SESSION['user_id']    = $id;
      $_SESSION['role']       = $role;
      header('Location: dashboard/admin/index.php');
      exit;
    } else {
      // 4) Failed login logging
      log_failed_login($user);
      $error = 'Invalid credentials.';
    }
    $stmt->close();
  }
}

// <!-- then your HTML form shows $error if set -->
