<?php
// File: config/bootstrap.php

// ─────────────────────────────────────────────────────────────────────────────
// 1) Session + Helpers
// ─────────────────────────────────────────────────────────────────────────────

ob_start();                  // <— START OUTPUT BUFFERING
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

////////////////////////////////////////////////////////////////////////////////
// Enforce role-based access
////////////////////////////////////////////////////////////////////////////////
function require_role(string $role): void {
    if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location:/artovue/login.php');
        exit;
    }
}

////////////////////////////////////////////////////////////////////////////////
// Render Admin Header & Sidebar
////////////////////////////////////////////////////////////////////////////////
function render_admin_header(string $pageTitle): void {
    global $conn;
    require_role('admin');

    // Fetch admin username
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($adminUser);
    $stmt->fetch();
    $stmt->close();

    $current = $_GET['page'] ?? 'dashboard';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title><?= htmlspecialchars($pageTitle) ?> — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: { 'admin-primary': '#9C27B0', 'admin-secondary': '#FFC107' } } } };
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6b21a8">
<link rel="icon" href="/assets/icons/icon-192.png">
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
      .then(() => console.log('✅ SW registered'))
      .catch(err => console.error('⚠️ SW registration failed:', err));
  }
</script>
  <link href="/artovue/assets/custom.css" rel="stylesheet"/>
  <style>a { text-decoration: none; }</style>
</head>
<body class="flex bg-gray-100 min-h-screen">
  <!-- Mobile Topbar -->
  <nav class="md:hidden bg-admin-primary text-white p-3 flex items-center justify-between">
    <button id="sidebarToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Admin Panel</span>
    <a href="/artovue/logout.php"><i class="bi bi-box-arrow-right"></i></a>
  </nav>

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-admin-secondary p-4 text-gray-900 transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out">
    <ul class="space-y-2">
      <?php
      $items = [
        'dashboard'            => ['bi-speedometer2',        'Overview'],
        'students'             => ['bi-people-fill',         'Students'],
        'add_student'          => ['bi-person-plus',         'Add Student'],
        'edit_student'         => ['bi-pencil-square',       'Edit Student'],
        'centres'         => ['bi-pencil-square',       'Manage Centres'],
        'attendance'           => ['bi-calendar-check',      'Attendance'],
        'assign_homework'      => ['bi-journal-text',        'Assign Homework'],
        'homework_centerwise'  => ['bi-journal-text',        'Homework Submissions'],
        'progress'             => ['bi-pencil-square',       'Progress'],
        'admin_payment'        => ['bi-currency-rupee',      'Payments'],
        'comp_requests'        => ['bi-currency-rupee',      'Comp Requests'],
        'star_manager'         => ['bi-star-fill',           'Star Manager'],
        'video_completions'    => ['bi-collection-play-fill','Video Completions'],
        'video_manager'        => ['bi-play-btn-fill',       'Video Manager'],
      ];
      foreach ($items as $key => [$icon, $label]) {
        $activeClass = $current === $key
          ? 'bg-admin-primary text-white'
          : 'text-gray-800 hover:bg-admin-primary hover:text-white';
        $aria        = $current === $key ? ' aria-current="page"' : '';
        echo "<li><a href='?page={$key}' class='flex items-center p-2 rounded-lg {$activeClass}'{$aria}><i class='bi {$icon} mr-3'></i>{$label}</a></li>";
      }
      ?>
    </ul>
  </aside>

  <!-- Main Content -->
  <div class="flex-1 flex flex-col">
    <!-- Desktop Topbar -->
    <header class="hidden md:flex bg-admin-primary text-white p-3 items-center justify-between">
      <div class="font-semibold"><i class="bi bi-shield-lock-fill mr-2"></i>Admin Panel</div>
      <div class="flex items-center space-x-4">
        <span><?= htmlspecialchars($adminUser) ?></span>
        <a href="/artovue/logout.php" class="border border-white rounded px-2 hover:bg-white hover:text-gray-800 transition">Logout</a>
      </div>
    </header>

    <main class="flex-1 p-6 overflow-auto">
      <h1 class="text-2xl font-bold mb-4"><?= htmlspecialchars($pageTitle) ?></h1>
<?php
}

function render_admin_footer(): void {
?>
    </main>
    <footer class="bg-admin-secondary text-gray-900 text-center py-2">&copy; <?= date('Y') ?> Rart Works</footer>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>document.getElementById('sidebarToggle').addEventListener('click', ()=>{document.getElementById('sidebar').classList.toggle('-translate-x-full');});</script>
</body>
</html>
<?php
}

////////////////////////////////////////////////////////////////////////////////
// Render Student Header & Sidebar
////////////////////////////////////////////////////////////////////////////////
function render_student_header(string $pageTitle): void {
    global $conn;
    require_role('student');

    // Fetch student username
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($stuUser);
    $stmt->fetch();
    $stmt->close();

    // Fetch unread notification count
    $stuId = $_SESSION['student_id'];
    $nStmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND is_read = 0");
    $nStmt->bind_param('i', $stuId);
    $nStmt->execute();
    $nStmt->bind_result($unreadCount);
    $nStmt->fetch();
    $nStmt->close();

    $current = $GLOBALS['page'] ?? 'dashboard';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title><?= htmlspecialchars($pageTitle) ?> — Student Portal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { theme: { extend: { colors: { 'student-primary':'#1E40AF','student-secondary':'#60A5FA' } } } };
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <link href="/artovue/assets/custom.css" rel="stylesheet"/>
  <style>a { text-decoration: none; }</style>
</head>
<body class="flex bg-gray-100 min-h-screen">

<!-- Mobile Topbar -->
<nav class="md:hidden bg-student-primary text-white p-3 flex items-center justify-between">
  <button id="sidebarToggle"><i class="bi bi-list text-2xl"></i></button>
  <span class="font-semibold">Student Portal</span>
  <div class="flex items-center space-x-3">
    <!-- Notification Bell -->
    <a href="/artovue/dashboard/student/?page=notifications" class="position-relative text-white">
      <i class="bi bi-bell-fill text-xl"></i>
      <?php if ($unreadCount > 0): ?>
        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
          <?= $unreadCount ?>
        </span>
      <?php endif; ?>
    </a>
    <!-- Logout -->
    <a href="/artovue/logout.php"><i class="bi bi-box-arrow-right text-xl"></i></a>
  </div>
</nav>

  <!-- Sidebar -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-student-secondary p-4 text-gray-900 transform -translate-x-full md:translate-x-0 transition-transform duration-200 ease-in-out">
    <div class="mb-4 border-b pb-2">
      <span class="font-bold text-lg">Student Portal</span>
    </div>
    <ul class="space-y-2">
      <?php
      $menu = [
        'dashboard'       => ['bi-speedometer2',   'Dashboard'],
        'homework'        => ['bi-journal-text',   'My Homework'],
        'attendance'      => ['bi-calendar-check', 'Attendance'],
        'progress'        => ['bi-bar-chart-line','My Progress'],
        'stars'           => ['bi-star-fill',      'Stars & Rewards'],
        'student_payment' => ['bi-currency-rupee', 'Payment'],
        'compensation'    => ['bi-clock-history',  'Compensation'],
        'profile'         => ['bi-person-circle',  'My Profile'],
        'notifications'   => ['bi-bell-fill',      'Notifications'],
        'logout'          => ['bi-box-arrow-right','Logout'],
      ];
      foreach ($menu as $key => [$icon, $label]) {
        $activeClass = $current === $key
          ? 'bg-student-primary text-white'
          : 'text-gray-800 hover:bg-student-primary hover:text-white';
        $href = $key === 'logout'
          ? '/artovue/logout.php'
          : "/artovue/dashboard/student/?page={$key}";
        $aria = $current === $key ? ' aria-current="page"' : '';
        echo "<li><a href='{$href}' class='flex items-center p-2 rounded-lg {$activeClass}'{$aria}><i class='bi {$icon} mr-3'></i>{$label}</a></li>";
      }
      ?>
    </ul>
  </aside>

  <!-- Main Content -->
  <div class="flex-1 flex flex-col">
  <!-- Desktop Topbar -->
<header class="hidden md:flex bg-student-primary text-white p-3 items-center justify-between">
  <div class="font-semibold"><i class="bi bi-shield-lock-fill mr-2"></i>Student Portal</div>
  <div class="flex items-center space-x-4">
    <!-- Notification Bell -->
    <a href="/artovue/dashboard/student/?page=notifications" class="position-relative text-white">
      <i class="bi bi-bell-fill text-2xl"></i>
      <?php if ($unreadCount > 0): ?>
        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
          <?= $unreadCount ?>
        </span>
      <?php endif; ?>
    </a>
    <!-- Username -->
    <span><?= htmlspecialchars($stuUser) ?></span>
    <!-- Logout -->
    <a href="/artovue/logout.php"
       class="border border-white rounded px-2 hover:bg-white hover:text-gray-800 transition">
      Logout
    </a>
  </div>
</header>

    <main class="flex-1 p-6 overflow-auto">
      <h1 class="text-2xl font-bold mb-4"><?= htmlspecialchars($pageTitle) ?></h1>
<?php
}

function render_student_footer(): void {
?>
    </main>
    <footer class="bg-student-secondary text-gray-900 text-center py-2">&copy; <?= date('Y') ?> Student Portal</footer>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>document.getElementById('sidebarToggle').addEventListener('click', ()=>{document.getElementById('sidebar').classList.toggle('-translate-x-full');});</script>
</body>
</html>
<?php
}
