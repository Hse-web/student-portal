<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/db.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid session. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            // Check if user exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                // Create token and expiration
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

                // Store token in DB
                $stmt2 = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)");
                $stmt2->bind_param('sss', $email, $token, $expires);
                $stmt2->execute();

                // Send email (replace with actual email logic)
                $resetUrl = "https://yourdomain.com/reset-password.php?token=$token";
                // mail($email, "Password Reset", "Click to reset: $resetUrl");

                $success = 'A password reset link has been sent to your email.';
            } else {
                $error = 'Email not found.';
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Forgot Password – Student Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center px-4">
  <div class="bg-white p-8 rounded-xl shadow-md w-full max-w-md">
    <h1 class="text-2xl font-bold mb-6 text-center">Forgot Password</h1>

    <?php if ($error): ?>
      <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
        <?= htmlspecialchars($error) ?>
      </div>
   <?php elseif ($success): ?>
  <div class="bg-green-100 text-green-700 p-3 rounded mb-4">
    <?= htmlspecialchars($success) ?>
    <div class="mt-2 text-sm text-green-800">
      <strong>Test Reset URL:</strong><br>
      <code class="break-words"><?= htmlspecialchars($resetUrl) ?></code>
    </div>
  </div>
<?php endif; ?>

    <form method="post" class="space-y-4" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
      
      <div>
        <label for="email" class="block mb-1 font-medium text-gray-700">Email Address</label>
        <input
          type="email"
          id="email"
          name="email"
          required
          class="w-full px-4 py-2 border border-gray-300 rounded-md focus:ring-2 focus:ring-blue-500"
          value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
        >
      </div>

      <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded-md hover:bg-blue-700 transition">
        Send Reset Link
      </button>

      <p class="text-sm text-center text-gray-500">
        <a href="login.php" class="text-blue-600 hover:underline">Back to login</a>
      </p>
    </form>
  </div>
</body>
</html>
