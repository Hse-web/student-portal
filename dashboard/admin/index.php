<?php
// File: dashboard/admin/index.php

ob_start();
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// 1) Early POST‐actions (with CSRF)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_GET['page'], $_POST['csrf_token'])
    && verify_csrf_token($_POST['csrf_token'])
) {
    switch ($_GET['page']) {
      case 'delete_student':
        include __DIR__.'/delete_student.php';
        exit;
      case 'delete_bulk':
        include __DIR__.'/delete_bulk.php';
        exit;
      case 'add_student':
        include __DIR__.'/add_student.php';
        exit;
      case 'edit_student':
        include __DIR__.'/edit_student.php';
        exit;
    }
}

// 2) Sidebar menu & page
$menu = [
  'dashboard'           => ['bi-speedometer2','Overview'],
  'students'            => ['bi-people-fill','Students'],
  'add_student'         => ['bi-person-plus','Add Student'],
  'edit_student'        => ['bi-pencil-square','Edit Student'],
  'attendance'          => ['bi-calendar-check','Attendance'],
  'assign_homework'     => ['bi-journal-text','Assign Homework'],
  'homework_centerwise' => ['bi-journal-text','Homework Submissions'],
  'progress'            => ['bi-pencil-square','Progress'],
  'admin_payment'       => ['bi-currency-rupee','Payments'],
  'comp_requests'       => ['bi-currency-rupee','Comp Requests'],
  'video_completions'   => ['bi-collection-play-fill','Video Completions'],
  'video_manager'       => ['bi-play-btn-fill','Video Manager'],
];
$page = $_GET['page'] ?? 'dashboard';
if (! isset($menu[$page])) {
    $page = 'dashboard';
}

// 3) Fetch admin username
$stmt = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();

// 4) Overview counts
$tableMap = ['students'=>'students'];
$counts   = [];
foreach ($tableMap as $k=>$t) {
    $cnt = $conn
      ->query("SELECT COUNT(*) AS c FROM `$t`")
      ->fetch_assoc()['c'];
    $counts[$k] = (int)$cnt;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin Panel</title>
 <!-- 2) Tailwind CDN -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- 3) Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>
  <!-- 1) Tailwind inline config (must come first) -->
  <script>
    window.tailwind = window.tailwind||{};
    window.tailwind.config = {
      theme:{ extend:{
        colors:{
          'admin-primary':'#9C27B0',
          'admin-secondary':'#FFC107'
        }
      }}
    };
  </script>
 
  <style> a{text-decoration:none;} </style>
</head>
<body class="bg-gray-100 min-h-screen flex">

  <!-- Sidebar -->
  <nav class="hidden md:block w-64 bg-admin-secondary text-gray-900 p-4">
    <ul class="space-y-2">
      <?php foreach($menu as $key=>[$icon,$label]): ?>
      <li>
        <a href="?page=<?= $key ?>"
           class="flex items-center p-2 rounded-lg transition
             <?= $page=== $key
               ? 'bg-admin-primary text-white'
               : 'text-gray-800 hover:bg-admin-primary hover:text-white' ?>">
          <i class="bi <?= $icon ?> mr-3"></i>
          <span><?= htmlspecialchars($label) ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <div class="flex-1 flex flex-col">

    <!-- Topbar -->
    <header class="bg-admin-primary text-white">
      <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
        <div class="flex items-center space-x-2">
          <i class="bi bi-shield-lock-fill"></i>
          <span class="font-semibold">Admin Panel</span>
        </div>
        <div class="flex items-center space-x-4">
          <span><?= htmlspecialchars($adminUsername) ?></span>
          <a href="/logout.php"
             class="px-3 py-1 border border-white rounded hover:bg-white hover:text-gray-800 transition">
            Logout
          </a>
        </div>
      </div>
    </header>

    <!-- Main -->
    <main class="flex-1 p-6 overflow-auto">
      <?php switch($page):
        case 'dashboard': ?>
          <h2 class="text-2xl font-semibold mb-4">Overview</h2>
          <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <?php foreach($tableMap as $k=>$_): ?>
            <div class="bg-white p-5 rounded-lg shadow text-center">
              <i class="bi <?= $menu[$k][0] ?> text-3xl text-admin-primary"></i>
              <h3 class="mt-2"><?= htmlspecialchars($menu[$k][1]) ?></h3>
              <div class="text-3xl font-bold"><?= $counts[$k] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php break;

        // include the rest of your pages by name:
        default:
          include __DIR__ . "/{$page}.php";
      endswitch; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-admin-secondary text-gray-900 text-center py-3">
      &copy; <?= date('Y') ?> Rart Works
    </footer>
  </div>
</body>
</html>
