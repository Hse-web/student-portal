<?php
// File: forgot-password.php

require_once __DIR__ . '/config/db.php';
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // valid for 1 hour

        $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE username = ?");
        $stmt->bind_param("sss", $token, $expires, $username);
        $stmt->execute();

        // Just show the reset link (since we're testing locally)
        $resetLink = "http://localhost/student-portal/reset-password.php?token=$token";
        $message = "Reset link (valid 1 hr): <a href='$resetLink'>$resetLink</a>";
    } else {
        $message = "No user found with that username.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
  <div class="bg-white p-8 rounded shadow-md w-full max-w-md">
    <h1 class="text-2xl font-bold mb-6">Forgot Password</h1>

    <?php if ($errors): ?>
      <div class="bg-red-100 text-red-700 p-3 rounded mb-4">
        <ul class="list-disc ml-5">
          <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="bg-green-100 text-green-700 p-3 rounded mb-4">
        <?= $success ?>
      </div>
    <?php else: ?>
      <form method="POST">
        <label class="block mb-2">Enter your email</label>
        <input type="email" name="email" class="w-full p-2 border rounded mb-4" required>
        <button type="submit" class="bg-purple-600 text-white px-4 py-2 rounded">Send Reset Link</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
