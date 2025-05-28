<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';

// Handle POST reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $newPass = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (strlen($newPass) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($newPass !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        // Validate token
        $stmt = $conn->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1");
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $stmt->bind_result($email);
        if ($stmt->fetch()) {
            $stmt->close();

            // Update user password
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $up = $conn->prepare("UPDATE users SET password = ? WHERE username = ?");
            $up->bind_param('ss', $hashed, $email);
            $up->execute();
            $up->close();

            // Delete token
            $del = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
            $del->bind_param('s', $token);
            $del->execute();
            $del->close();

            $success = 'Password reset successfully. You can now <a href="login.php" class="text-blue-600 underline">login</a>.';
        } else {
            $error = 'Invalid or expired token.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Reset Password – Student Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center px-4">
  <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
    <h1 class="text-2xl font-bold mb-6 text-center">Reset Password</h1>

    <?php if ($error): ?>
      <div class="bg-red-100 text-red-700 p-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
    <?php elseif ($success): ?>
      <div class="bg-green-100 text-green-700 p-3 rounded mb-4"><?= $success ?></div>
    <?php endif; ?>

    <?php if (!$success): ?>
    <form method="post" class="space-y-4">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

      <div>
        <label for="password" class="block mb-1 font-medium text-gray-700">New Password</label>
        <input
          type="password"
          id="password"
          name="password"
          required
          minlength="6"
          class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"
        >
      </div>

      <div>
        <label for="confirm" class="block mb-1 font-medium text-gray-700">Confirm Password</label>
        <input
          type="password"
          id="confirm"
          name="confirm"
          required
          class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"
        >
      </div>

      <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 transition">
        Reset Password
      </button>
    </form>
    <?php endif; ?>
  </div>
</body>
</html>
