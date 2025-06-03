<?php
// File: login.php
// ---------------------------

require_once __DIR__ . '/config/bootstrap.php';
// At this point, session is already started by session.php, so $_SESSION is alive.

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1) CSRF check
    if (empty($_POST['csrf_token']) ||
        ! verify_csrf_token($_POST['csrf_token'])
    ) {
        $error = 'Invalid session. Please reload and try again.';
    }
    // 2) Throttle repeated failures
    elseif (! throttle_login($_POST['username'] ?? '')) {
        $error = 'Too many failed attempts; please wait and try again.';
    }
    else {
        // 3) Lookup user by username
        $stmt = $conn->prepare(
            'SELECT id, password, role 
               FROM users 
              WHERE username = ? 
              LIMIT 1'
        );
        $stmt->bind_param('s', $_POST['username']);
        $stmt->execute();
        $stmt->bind_result($userId, $hash, $role);
        $found = $stmt->fetch();
        $stmt->close();

        // 4) Verify password
        if ($found && password_verify($_POST['password'], $hash)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id']   = (int)$userId;
            $_SESSION['role']      = $role;

            if ($role === 'student') {
                $stm = $conn->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
                $stm->bind_param('i', $userId);
                $stm->execute();
                $stm->bind_result($stuId);
                if ($stm->fetch()) {
                    $_SESSION['student_id'] = (int)$stuId;
                } else {
                    $error = 'Student profile not found. Contact support.';
                    session_unset();
                    session_destroy();
                    session_start();
                }
                $stm->close();
            }

            if (empty($error)) {
                // Once login is successful, remove the old CSRF token so we can issue a fresh one next page:
                unset($_SESSION['csrf_token']);

                // Redirect to dashboard
                header('Location: dashboard/' . ($role === 'admin' ? 'admin' : 'student') . '/index.php');
                exit;
            }
        }

        log_failed_login($_POST['username'] ?? '');
        if (empty($error)) {
            $error = 'Invalid credentials.';
        }
    }
}

// 5) If we reach here (either GET or after a failed POST), generate a token for the form
$token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login – Student Portal</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="w-full max-w-md bg-white shadow-md rounded-xl p-8">
    <h2 class="text-2xl font-bold text-center mb-6">Sign In</h2>

    <?php if ($error): ?>
      <div class="bg-red-100 text-red-700 px-4 py-3 rounded mb-4 text-sm">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate class="space-y-5">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">

      <div>
        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
          Username (email)
        </label>
        <input
          type="email"
          id="username"
          name="username"
          required
          value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
          class="w-full border border-gray-300 rounded-md px-4 py-2
                 focus:ring-2 focus:ring-blue-500 focus:outline-none"
        >
      </div>

      <div>
        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
          Password
        </label>
        <input
          type="password"
          id="password"
          name="password"
          required
          class="w-full border border-gray-300 rounded-md px-4 py-2
                 focus:ring-2 focus:ring-blue-500 focus:outline-none"
        >
      </div>

      <button
        type="submit"
        class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 transition font-semibold"
      >
        Login
      </button>

      <div class="text-center mt-4">
        <a href="forgot-password.php" class="text-blue-600 text-sm hover:underline">
          Forgot your password?
        </a>
      </div>
    </form>
  </div>
</body>
</html>
