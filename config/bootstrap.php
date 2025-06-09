<?php
// File: config/bootstrap.php

// ─────────────────────────────────────────────────────────────────────────────
// 1) Session + Helpers
// ─────────────────────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';

////////////////////////////////////////////////////////////////////////////////
// require_role()
// ────────────────────────────────────────────────────────────────────────────
function require_role(string $role): void {
    if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== $role) {
        header('Location:/student-portal/login.php');
        exit;
    }
}

////////////////////////////////////////////////////////////////////////////////
// Admin wrapper
// ────────────────────────────────────────────────────────────────────────────
function render_admin_header(string $pageTitle): void {
    global $conn;
    require_role('admin');

    // Fetch admin username
    $stmt = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
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
    tailwind.config = {
      theme:{ extend:{
        colors:{
          'admin-primary':'#9C27B0',
          'admin-secondary':'#FFC107'
        }
      }}
    };
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <link href="/student-portal/assets/custom.css" rel="stylesheet"/>
  <style>a{text-decoration:none;}</style>
</head>
<body class="flex bg-gray-100 min-h-screen">

  <!-- Mobile Topbar -->
  <nav class="md:hidden bg-admin-primary text-white p-3 flex items-center justify-between">
    <button id="sidebarToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Admin Panel</span>
    <a href="/student-portal/logout.php"><i class="bi bi-box-arrow-right"></i></a>
  </nav>

  <!-- Sidebar -->
  <aside id="sidebar" class="
      fixed inset-y-0 left-0 z-50 w-64
      bg-admin-secondary p-4 text-gray-900
      transform -translate-x-full md:translate-x-0
      transition-transform duration-200 ease-in-out
    ">
    <ul class="space-y-2">
      <?php
      $items = [
        'dashboard'=>['bi-speedometer2','Overview'],
        'students'=>['bi-people-fill','Students'],
        'add_student'=>['bi-person-plus','Add Student'],
        'edit_student'=>['bi-pencil-square','Edit Student'],
        'attendance'=>['bi-calendar-check','Attendance'],
        'assign_homework'=>['bi-journal-text','Assign Homework'],
        'homework_centerwise'=>['bi-journal-text','Homework Submissions'],
        'progress'=>['bi-pencil-square','Progress'],
        'admin_payment'=>['bi-currency-rupee','Payments'],
        'comp_requests'=>['bi-currency-rupee','Comp Requests'],
        'star_manager'=>['bi-star-fill','Star Manager'],
        'video_completions'=>['bi-collection-play-fill','Video Completions'],
        'video_manager'=>['bi-play-btn-fill','Video Manager'],
      ];
      foreach($items as $key=>[$icon,$label]) {
        $active = $current===$key
                ? 'bg-admin-primary text-white'
                : 'text-gray-800 hover:bg-admin-primary hover:text-white';
        $aria   = $active? ' aria-current="page"':'';
        echo "<li>
                <a href=\"?page={$key}\" class=\"flex items-center p-2 rounded-lg {$active}\"{$aria}>
                  <i class=\"bi {$icon} mr-3\"></i>{$label}
                </a>
              </li>";
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
        <a href="/student-portal/logout.php"
           class="border border-white rounded px-2 hover:bg-white hover:text-gray-800 transition">
          Logout
        </a>
      </div>
    </header>

    <main class="flex-1 p-6 overflow-auto">
      <h1 class="text-2xl font-bold mb-4"><?= htmlspecialchars($pageTitle) ?></h1>
<?php
}

function render_admin_footer(): void {
    ?>
    </main>
    <footer class="bg-admin-secondary text-gray-900 text-center py-2">
      &copy; <?= date('Y') ?> Rart Works
    </footer>
  </div><!-- /.flex-1 -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('sidebarToggle')
      .addEventListener('click', ()=>{
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
      });
  </script>
</body>
</html>
<?php
}

////////////////////////////////////////////////////////////////////////////////
// Student wrapper
// ────────────────────────────────────────────────────────────────────────────
function render_student_header(string $pageTitle): void {
    global $conn;
    require_role('student');

    // Fetch student username
    $stmt = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($stuUser);
    $stmt->fetch();
    $stmt->close();

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
    tailwind.config = {
      theme:{ extend:{
        colors:{
          'student-primary':'#1E40AF',
          'student-secondary':'#60A5FA'
        }
      }}
    };
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <link href="/student-portal/assets/custom.css" rel="stylesheet"/>
  <style>a{text-decoration:none;}</style>
</head>
<body class="flex bg-gray-100 min-h-screen">

  <!-- Mobile Topbar -->
  <nav class="md:hidden bg-student-primary text-white p-3 flex items-center justify-between">
    <button id="sidebarToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Student Portal</span>
    <a href="/student-portal/logout.php"><i class="bi bi-box-arrow-right"></i></a>
  </nav>

  <!-- Sidebar -->
  <aside id="sidebar" class="
      fixed inset-y-0 left-0 z-50 w-64
      bg-student-secondary p-4 text-gray-900
      transform -translate-x-full md:translate-x-0
      transition-transform duration-200 ease-in-out
    ">
    <div class="mb-4 border-b pb-2">
      <span class="font-bold text-lg">Student Portal</span>
    </div>
    <ul class="space-y-2">
      <?php
      $menu = [
        'dashboard'=>['bi-speedometer2','Dashboard'],
        'homework'=>['bi-journal-text','My Homework'],
        'attendance'=>['bi-calendar-check','Attendance'],
        'progress'=>['bi-bar-chart-line','My Progress'],
        'stars'=>['bi-star-fill','Stars & Rewards'],
        'student_payment'=>['bi-currency-rupee','Payment'],
        'compensation'=>['bi-clock-history','Compensation'],
        'profile'=>['bi-person-circle','My Profile'],
        'notifications'=>['bi-bell-fill','Notifications'],
        'logout'=>['bi-box-arrow-right','Logout'],
      ];
      foreach($menu as $key=>[$icon,$lab]) {
        $active = $current===$key
                ? 'bg-student-primary text-white'
                : 'text-gray-800 hover:bg-student-primary hover:text-white';
        $href   = $key==='logout'
                ? '/student-portal/logout.php'
                : "/student-portal/dashboard/student/?page={$key}";
        $aria   = $active? ' aria-current="page"':'';
        echo "<li>
                <a href=\"{$href}\" class=\"flex items-center p-2 rounded-lg {$active}\"{$aria}>
                  <i class=\"bi {$icon} mr-3\"></i>{$lab}
                </a>
              </li>";
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
        <span><?= htmlspecialchars($stuUser) ?></span>
        <a href="/student-portal/logout.php"
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
    <footer class="bg-student-secondary text-gray-900 text-center py-2">
      &copy; <?= date('Y') ?> Student Portal
    </footer>
  </div><!-- /.flex-1 -->

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('sidebarToggle')
      .addEventListener('click', ()=>{
        document.getElementById('sidebar').classList.toggle('-translate-x-full');
      });
  </script>
</body>
</html>
<?php
}
