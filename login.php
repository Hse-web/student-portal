<?php
// File: login.php
// -----------------------------
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php'; // provides $conn

// Generate CSRF token if not present
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid session. Please try again.';
    } elseif (!throttle_login($_POST['username'] ?? '')) {
        $error = 'Too many failed attempts. Try again in an hour.';
    } else {
        // Lookup user credentials
        $stmt = $conn->prepare('SELECT id, password, role FROM users WHERE username = ?');
        $stmt->bind_param('s', $_POST['username']);
        $stmt->execute();
        $stmt->bind_result($userId, $hash, $role);
        $fetched = $stmt->fetch();
        $stmt->close();

        if ($fetched && password_verify($_POST['password'], $hash)) {
            // Successful login
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['role']      = $role;

            // Assign student or admin ID
            if ($role === 'student') {
                // Fetch actual student table ID by user_id
                $stmt2 = $conn->prepare('SELECT id FROM students WHERE user_id = ?');
                $stmt2->bind_param('i', $userId);
                $stmt2->execute();
                $stmt2->bind_result($studentId);
                $stmt2->fetch();
                $stmt2->close();
                $_SESSION['student_id'] = $studentId;
                $redirect = 'dashboard/student/index.php';
            } else {
                // Admin user
                $_SESSION['admin_id'] = $userId;
                $redirect = 'dashboard/admin/index.php';
            }

            // Invalidate used CSRF token
            unset($_SESSION['csrf_token']);

            header('Location: ' . $redirect);
            exit;
        } else {
            log_failed_login($_POST['username'] ?? '');
            $error = 'Invalid credentials.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login - Student Portal</title>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Custom Styles -->
  <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="row justify-content-center">
      <div class="col-md-4">
        <h2 class="mb-4 text-center">Login</h2>
        <?php if ($error): ?>
          <div class="alert alert-danger"><?=htmlspecialchars($error)?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
          <div class="mb-3">
            <label for="username" class="form-label">Username</label>
            <input type="text" id="username" name="username" class="form-control" required>
          </div>
          <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
      </div>
    </div>
  </div>
  <!-- Bootstrap JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
