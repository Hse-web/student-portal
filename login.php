<?php
// File: login.php
require_once __DIR__ . '/config/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || ! verify_csrf_token($_POST['csrf_token'])) {
        $error = 'Invalid session. Please reload and try again.';
    } elseif (! throttle_login($_POST['username'] ?? '')) {
        $error = 'Too many failed attempts; please wait and try again.';
    } else {
        $stmt = $conn->prepare('SELECT id,password,role FROM users WHERE username=? LIMIT 1');
        $stmt->bind_param('s', $_POST['username']);
        $stmt->execute();
        $stmt->bind_result($userId,$hash,$role);
        $found = $stmt->fetch();
        $stmt->close();

        if ($found && password_verify($_POST['password'],$hash)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id']   = (int)$userId;
            $_SESSION['role']      = $role;

            if ($role==='student') {
                $stm = $conn->prepare("SELECT id FROM students WHERE user_id=? LIMIT 1");
                $stm->bind_param('i',$userId);
                $stm->execute();
                $stm->bind_result($stuId);
                if ($stm->fetch()) {
                    $_SESSION['student_id'] = (int)$stuId;
                } else {
                    $error = 'Student profile not found.';
                    session_unset(); session_destroy(); session_start();
                }
                $stm->close();
            }

            if (!$error) {
                unset($_SESSION['csrf_token']);
                header('Location: dashboard/'.($role==='admin'?'admin':'student').'/index.php');
                exit;
            }
        }

        log_failed_login($_POST['username'] ?? '');
        if (!$error) $error = 'Invalid credentials.';
    }
}

$token = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Login – Artovue</title>
  <link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6b21a8">
<link rel="icon" href="/assets/icons/icon-192.png">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    /* subtle fade behind the form */
    .bg-glass {
      background: rgba(255,255,255,0.8);
      backdrop-filter: blur(12px);
    }
   .logo-gradient {
  /* a smooth left‐to‐right blend through all six letter-colors */
  background: linear-gradient(
    90deg,
    /* A = golden orange  */  #FDB813 0%,
    /* R = pink‐magenta   */  #FF2F6C 20%,
    /* T = deep violet    */  #8F00FF 40%,
    /* O = emerald green  */  #00D272 60%,
    /* V = aqua cyan      */  #00C4F0 80%,
    /* U = royal blue     */  #005AFF 100%
  );
  /* clip the gradient to text */
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  color: transparent;
}
  </style>
</head>
<body class="relative overflow-hidden bg-gradient-to-r from-blue-50 to-pink-50 min-h-screen flex items-center justify-center p-4">

  <!-- Left character -->
  <img src="assets/girl-artist01.png"
       alt="Girl painter"
       class="relative w-32 sm:w-36 md:w-40 lg:w-44 xl:w-48 2xl:w-56 -mr-4 sm:-mr-8 md:-mr-12 z-20" />

  <!-- Right character -->
  <img src="assets/boy-artist02.png"
       alt="Boy painter"
       class="hidden md:block w-28 sm:w-32 md:w-36 lg:w-40 xl:w-44 2xl:w-52 -ml-4 sm:-ml-8 md:-ml-12 z-20" />

  <!-- Form + logo wrapper -->
  <div class="relative z-20 w-full max-w-md">
    <!-- Logo -->
    <div class="mb-6 text-center">
      <img src="assets/logo_desk.png" alt="Artovue logo" class="mx-auto h-12 w-auto" />
    </div>

    <!-- Glass-style panel -->
    <div class="bg-glass rounded-3xl shadow-xl p-8">
      <!-- Gradient-text heading -->
    <h1 class="logo-gradient text-4xl font-extrabold text-center">Artovue</h1>

      <?php if ($error): ?>
        <div class="mb-4 px-4 py-2 bg-red-100 text-red-800 rounded">
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" novalidate class="space-y-5">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token) ?>">

        <div>
          <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
            Username (email)
          </label>
          <input type="email" id="username" name="username" required
                 value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                 class="w-full border border-gray-300 rounded-lg px-4 py-2
                        focus:ring-2 focus:ring-blue-500 focus:outline-none" />
        </div>

        <div>
          <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
            Password
          </label>
          <input type="password" id="password" name="password" required
                 class="w-full border border-gray-300 rounded-lg px-4 py-2
                        focus:ring-2 focus:ring-blue-500 focus:outline-none" />
        </div>

        <button type="submit"
                class="w-full bg-blue-600 text-white py-2 rounded-lg text-lg font-medium
                       hover:bg-blue-700 transition">
          Login
        </button>

        <div class="text-center">
          <a href="forgot-password.php" class="text-blue-600 hover:underline text-sm">
            Forgot your password?
          </a>
        </div>
      </form>
    </div>
  </div>
<!-- Service Worker -->
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
      .then(reg => console.log('✅ Service Worker Registered'))
      .catch(err => console.error('Service Worker Error:', err));
  }
</script>
</body>
</html>
