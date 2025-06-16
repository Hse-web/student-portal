<?php
// File: forgot-password.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/config/db.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);

    // Now also fetch the student's email from the database
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($userId, $username);
        $stmt->fetch();

        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

        $updateStmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $updateStmt->bind_param("ssi", $token, $expires, $userId);
        $updateStmt->execute();

        $resetLink = "https://www.rartwork.com/artovue/reset-password.php?token=$token";

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.hostinger.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'enrollment@rartwork.com'; // Your Hostinger email
            $mail->Password = '^*cDmaO#t#Ckq!6PJ';                        // Your email password
            $mail->SMTPSecure = 'tls';
            $mail->Port = 587;

            $mail->setFrom('enrollment@rartwork.com', 'Artovue');
            $mail->addAddress($username);
            $mail->isHTML(true);
            $mail->Subject = 'Password Reset Request';
            $mail->Body = "Hello <strong>$username</strong>,<br><br>Click <a href='$resetLink'>here</a> to reset your password. This link is valid for 1 hour.<br><br>Regards,<br>Artovue Team <br>Powered by Rartworks";

            if ($mail->send()) {
                $success = "✅ Reset link has been sent to your email address.";
            } else {
                $errors[] = "⚠️ Mail could not be sent.";
            }
        } catch (Exception $e) {
            $errors[] = "Mailer Error: " . $mail->ErrorInfo;
        }
    } else {
        $errors[] = "No account found with that username.";
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
        <label class="block mb-2">Enter your username</label>
        <input type="text" name="username" class="w-full p-2 border rounded mb-4" required>
        <button type="submit" class="bg-purple-600 text-white px-4 py-2 rounded">Send Reset Link</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
