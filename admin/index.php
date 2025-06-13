<?php
// File: dashboard/admin/index.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../helpers/functions.php';

// 1) Early POST actions (delete_student, delete_bulk, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $p = $_GET['page'] ?? '';
  if ($p === 'delete_student') {
    include __DIR__ . '/delete_student.php';
    exit;
  }
  if ($p === 'delete_bulk') {
    include __DIR__ . '/delete_bulk.php';
    exit;
  }
  // add more as needed…
}

// 2) Build menu + current page
$menu = [
  'dashboard'           => ['bi-speedometer2',         'Overview'],
  'students'            => ['bi-people-fill',          'Students'],
  'add_student'         => ['bi-person-plus',          'Add Student'],
  'edit_student'        => ['bi-pencil-square',        'Edit Student'],
  'attendance'          => ['bi-calendar-check',       'Attendance'],
  'assign_homework'     => ['bi-journal-text',         'Assign Homework'],
  'homework_centerwise' => ['bi-journal-text',         'Homework Submissions'],
  'progress'            => ['bi-bar-chart-line',       'Progress'],
  'admin_payment'       => ['bi-currency-rupee',       'Payments'],
  'comp_requests'       => ['bi-currency-rupee',       'Comp Requests'],
  'star_manager'        => ['bi-star-fill',            'Star Manager'],
  'art_groups'          => ['bi-grid-3x3-gap-fill',   'Art Groups'],
   'student_promotions'  => ['bi-award-fill',          'Promotions'],
  'video_completions'   => ['bi-collection-play-fill', 'Video Completions'],
  'video_manager'       => ['bi-play-btn-fill',        'Video Manager'],
];

$page = $_GET['page'] ?? 'dashboard';
if (!isset($menu[$page])) {
  $page = 'dashboard';
}

// 3) Fetch admin username
$stmt = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();

// 4) Counts for dashboard
$tableMap = ['students'=>'students'];
$counts = [];
foreach ($tableMap as $k=>$tbl) {
  $r = $conn->query("SELECT COUNT(*) AS cnt FROM `$tbl`")->fetch_assoc();
  $counts[$k] = (int)$r['cnt'];
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Admin Panel | Rart Works</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>

  <!-- Tailwind & Bootstrap Icons -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: {
        colors: {
          'admin-primary':   '#9C27B0',
          'admin-secondary': '#FFC107'
        }
      }}
    };
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>
  <style> a{ text-decoration:none } </style>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">

  <!-- Mobile Topbar -->
  <nav class="md:hidden bg-admin-primary text-white p-3 flex items-center justify-between">
    <button id="mobileMenuToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Admin Panel</span>
    <a href="/artovue/logout.php"><i class="bi bi-box-arrow-right text-2xl"></i></a>
  </nav>

  <!-- Mobile Slide-Down Menu -->
  <div id="mobileMenu" class="hidden md:hidden bg-admin-secondary text-gray-900 shadow-lg z-0">
    <ul class="flex flex-col divide-y divide-gray-200">
      <?php foreach($menu as $key=>[$icon,$label]): 
        $cls = ($page=== $key)
             ? 'bg-admin-primary text-white'
             : 'hover:bg-admin-primary hover:text-white';
      ?>
        <li>
          <a href="?page=<?= $key ?>"
             class="flex items-center p-3 <?= $cls ?> transition">
            <i class="bi <?= $icon ?> mr-3"></i>
            <?= htmlspecialchars($label) ?>
          </a>
        </li>
      <?php endforeach; ?>
      <li>
        <a href="/artovue/logout.php"
           class="flex items-center p-3 hover:bg-admin-primary hover:text-white transition">
          <i class="bi bi-box-arrow-right mr-3"></i> Logout
        </a>
      </li>
    </ul>
  </div>

  <div class="flex flex-1">
    <!-- Desktop Sidebar -->
    <aside class="hidden md:block w-64 bg-admin-secondary p-4 text-gray-900">
      <ul class="space-y-2">
        <?php foreach($menu as $key=>[$icon,$label]):
          $cls = ($page=== $key)
               ? 'bg-admin-primary text-white'
               : 'hover:bg-admin-primary hover:text-white';
        ?>
          <li>
            <a href="?page=<?= $key ?>"
               class="flex items-center p-2 rounded-lg <?= $cls ?> transition"
               <?= $page=== $key?'aria-current="page"':'' ?>>
              <i class="bi <?= $icon ?> mr-3"></i>
              <?= htmlspecialchars($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
        <li class="mt-4">
          <a href="/artovue/logout.php"
             class="flex items-center p-2 rounded-lg text-gray-800 hover:bg-admin-primary hover:text-white transition">
            <i class="bi bi-box-arrow-right mr-3"></i> Logout
          </a>
        </li>
      </ul>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
      <!-- Desktop Topbar -->
      <header class="hidden md:flex bg-admin-primary text-white px-6 py-3 justify-between">
        <div class="font-semibold"><i class="bi bi-shield-lock-fill"></i> Admin Panel</div>
        <div><?= htmlspecialchars($adminUsername) ?></div>
      </header>

      <main class="relative z-10 flex-1 overflow-auto p-6">
        <?php switch($page):
          case 'dashboard': ?>
            <h2 class="text-2xl font-semibold mb-6">Overview</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
              <?php foreach($tableMap as $k=>$_): ?>
                <div class="bg-white p-5 rounded-lg shadow text-center">
                  <i class="bi <?= $menu[$k][0] ?> text-3xl text-admin-primary"></i>
                  <h3 class="mt-2 text-lg"><?= $menu[$k][1] ?></h3>
                  <div class="mt-1 text-3xl font-bold"><?= $counts[$k] ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php break;

          // all other pages just include
          default:
            $f = __DIR__ . "/{$page}.php";
            if (is_file($f)) {
              include $f;
            } else {
              echo '<p class="text-red-600">Page not found.</p>';
            }
        endswitch; ?>
      </main>

      <footer class="bg-admin-secondary text-gray-900 text-center py-4">
        &copy; <?= date('Y') ?> Rart Works
      </footer>
    </div>
  </div>

  <!-- JS: Bootstrap + Mobile Menu Toggle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('mobileMenuToggle')
      .addEventListener('click', () => {
        document.getElementById('mobileMenu')
          .classList.toggle('hidden');
      });
  </script>
</body>
</html>
