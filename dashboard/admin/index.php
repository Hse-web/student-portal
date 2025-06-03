<?php
// File: dashboard/admin/index.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

ob_start();

// ─── 1) Early POST Actions: if someone POSTs to delete a student or delete bulk, handle it now ────────────────
//    (This ensures the action is processed before we render any HTML.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['page'] ?? '') === 'delete_student') {
    include __DIR__ . '/delete_student.php';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['page'] ?? '') === 'delete_bulk') {
    include __DIR__ . '/delete_bulk.php';
    exit;
}
// You can repeat this pattern for any other “action” pages that need to run first:

// if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['page'] ?? '') === 'some_other_action') {
//     include __DIR__ . '/some_other_action.php';
//     exit;
// }


// ─── 2) Build the $menu array & decide which $page we’re on ──────────────────────────────────────────
//    The array keys (e.g. 'students', 'attendance', 'star_manager', etc.) must match the sub‐folders/files below.
$menu = [
    'dashboard'           => ['bi-speedometer2',        'Overview'],
    'students'            => ['bi-people-fill',         'Students'],
    'add_student'         => ['bi-person-plus',         'Add Student'],
    'edit_student'        => ['bi-pencil-square',       'Edit Student'],
    'attendance'          => ['bi-calendar-check',      'Attendance'],
    'assign_homework'     => ['bi-journal-text',        'Assign Homework'],
    'homework_centerwise' => ['bi-journal-text',        'Homework Submissions'],
    'progress'            => ['bi-bar-chart-line',      'Progress'],
    'admin_payment'       => ['bi-currency-rupee',      'Payments'],
    'comp_requests'       => ['bi-currency-rupee',      'Comp Requests'],

    // ─── Newly added “Star Manager” menu item ───────────────────────────────
    'star_manager'        => ['bi-star-fill',           'Star Manager'],

    'video_completions'   => ['bi-collection-play-fill','Video Completions'],
    'video_manager'       => ['bi-play-btn-fill',       'Video Manager'],
];

$page = $_GET['page'] ?? 'dashboard';
if (! array_key_exists($page, $menu)) {
    $page = 'dashboard';
}


// ─── 3) Fetch the logged‐in admin’s username (for the topbar) ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();


// ─── 4) If we’re on “dashboard,” compute the simple overview counts ──────────────────────────────────────
$tableMap = [
    'students' => 'students'
];

$counts = [];
foreach ($tableMap as $key => $tbl) {
    $row          = $conn->query("SELECT COUNT(*) AS cnt FROM `$tbl`")->fetch_assoc();
    $counts[$key] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Admin Panel | Rart Works</title>
  <meta name="viewport" content="width=device-width, initial-scale=1" />

  <!-- 1) Tailwind JIT (via CDN) -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'admin-primary':   '#9C27B0', /* Purple */
            'admin-secondary': '#FFC107'  /* Yellow */
          }
        }
      }
    }
  </script>

  <!-- 2) Bootstrap Icons (for <i class="bi …">) -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet"
  />

  <!-- 3) Remove underlines from all <a> by default -->
  <style> a { text-decoration: none; } </style>
</head>
<body class="bg-gray-100 min-h-screen flex">

  <!-- ────────────────────────────────────────────────────────────────────────
       Sidebar (hidden on mobile, shows on md+)
       ──────────────────────────────────────────────────────────────────────── -->
  <nav class="hidden md:block w-64 bg-admin-secondary text-gray-900 p-4">
    <ul class="space-y-2">
      <?php foreach ($menu as $key => [$iconClass, $labelText]): ?>
        <li>
          <a href="?page=<?= $key ?>"
             class="
               flex items-center p-2 rounded-lg transition
               <?= ($page === $key)
                   ? 'bg-admin-primary text-white'
                   : 'text-gray-800 hover:bg-admin-primary hover:text-white focus:bg-admin-primary focus:text-white' ?>"
             <?= ($page === $key) ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= htmlspecialchars($iconClass) ?> mr-3 text-lg"></i>
            <span class="font-medium"><?= htmlspecialchars($labelText) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <!-- ────────────────────────────────────────────────────────────────────────
       Main container (Sidebar + Page content)
       ──────────────────────────────────────────────────────────────────────── -->
  <div class="flex-1 flex flex-col">

    <!-- ────────────────────────────────────────────────────────────────────────
         Topbar (always visible)
         ──────────────────────────────────────────────────────────────────────── -->
    <header class="bg-admin-primary text-white">
      <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
        <div class="flex items-center space-x-2 text-lg font-semibold">
          <i class="bi bi-shield-lock-fill"></i>
          <span>Admin Panel</span>
        </div>
        <div class="flex items-center space-x-4">
          <span><?= htmlspecialchars($adminUsername) ?></span>
          <a href="/student-portal/logout.php"
             class="px-3 py-1 border border-white rounded-lg
                    hover:bg-white hover:text-gray-800 transition">
            Logout
          </a>
        </div>
      </div>
    </header>

    <!-- ────────────────────────────────────────────────────────────────────────
         Page Content (rendered inside <main>…</main>)
         ──────────────────────────────────────────────────────────────────────── -->
    <main class="flex-1 p-6 overflow-auto">
      <?php switch ($page):

        // ─────────────────────────── “Overview” (dashboard) ───────────────────────────
        case 'dashboard': ?>
          <h2 class="text-2xl font-semibold text-gray-800 mb-6">Overview</h2>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($tableMap as $k => $_): ?>
              <div class="bg-white p-5 rounded-lg shadow hover:shadow-md transition text-center">
                <i class="bi <?= htmlspecialchars($menu[$k][0]) ?> text-3xl text-admin-primary"></i>
                <h3 class="mt-2 text-lg font-medium text-gray-700">
                  <?= htmlspecialchars($menu[$k][1]) ?>
                </h3>
                <div class="mt-1 text-3xl font-bold text-gray-900">
                  <?= $counts[$k] ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php break;


        // ───────────────────────── “Students” List ───────────────────────────────
        case 'students':
          include __DIR__ . '/students.php';
          break;


        // ───────────────────────── “Add Student” Form ────────────────────────────
        case 'add_student':
          include __DIR__ . '/add_student.php';
          break;


        // ───────────────────────── “Edit Student” Form ────────────────────────────
        case 'edit_student':
          include __DIR__ . '/edit_student.php';
          break;


        // ───────────────────────── “Attendance” Page ─────────────────────────────
        case 'attendance':
          include __DIR__ . '/attendance.php';
          break;


        // ──────────────────────── “Assign Homework” Page ─────────────────────────
        case 'assign_homework':
          include __DIR__ . '/assign_homework.php';
          break;


        // ───────────────────── “Homework Submissions (center‐wise)” ─────────────────
        case 'homework_centerwise':
          include __DIR__ . '/homework_centerwise.php';
          break;


        // ───────────────────────── “Progress” Page ─────────────────────────────────
        case 'progress':
          include __DIR__ . '/progress.php';
          break;


        // ───────────────────────── “Payments (Admin)” ──────────────────────────────
        case 'admin_payment':
          include __DIR__ . '/admin_payment.php';
          break;


        // ───────────────────────── “Comp Requests” ─────────────────────────────────
        case 'comp_requests':
          include __DIR__ . '/comp_requests.php';
          break;


        // ───────────────────────── “Star Manager” ─────────────────────────────────
        case 'star_manager':
          include __DIR__ . '/star_manager.php';
          break;


        // ───────────────────────── “Video Completions” ─────────────────────────────
        case 'video_completions':
          include __DIR__ . '/video_completions.php';
          break;


        // ───────────────────────── “Video Manager” ─────────────────────────────────
        case 'video_manager':
          include __DIR__ . '/video_manager.php';
          break;


        // ─────────────────────── Default: Page Not Found ──────────────────────────
        default:
          echo '<p class="text-red-600">Page not found.</p>';
      endswitch; ?>
    </main>

    <!-- ────────────────────────────────────────────────────────────────────────
         Footer (site‐wide)
         ──────────────────────────────────────────────────────────────────────── -->
    <footer class="bg-admin-secondary text-gray-900 text-center text-sm py-4">
      &copy; <?= date('Y') ?> Rart Works
    </footer>
  </div>
</body>
</html>
