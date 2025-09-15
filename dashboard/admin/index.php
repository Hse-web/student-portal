<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// File: dashboard/admin/index.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');
date_default_timezone_set('Asia/Kolkata');

// ─── Sidebar menu definition ───────────────────────────────────────
$menu = [
  'Dashboard' => ['dashboard'=>['bi-speedometer2','Overview']],
  'Students'  => [
    'students'=>['bi-people-fill','All Students'],
    'add_student'=>['bi-person-plus','Add Student'],
    'edit_student'=>['bi-pencil-square','Edit Student'],
    'holds'=>['bi-people-fill','Non-Appearance']
  ],
  'Fee Management'=>[
    'centres'=>['bi-geo-alt-fill','Manage Centres'],
    'payment_plans'=>['bi-cash-coin','Manage Plans'],
    'admin_payment'=>['bi-currency-rupee','Payments'],
    'fee_analytics'=>['bi-graph-up','Fee Analytics'],
    'comp_requests'=>['bi-wallet2','Comp Requests'],
    'student_subscriptions'=>['bi-journal-check','Subscriptions'],
  ],
  'Learning & Progress'=>[
    'attendance'=>['bi-calendar-check','Attendance'],
    'assign_homework'=>['bi-pencil-square','Assign Homework'],
    'homework_centerwise'=>['bi-file-earmark-richtext','Submissions'],
    'progress'=>['bi-bar-chart-line','Progress'],
    'star_manager'=>['bi-star-fill','Star Manager'],
    'art_groups'=>['bi-grid-3x3-gap-fill','Art Groups'],
    'student_promotions'=>['bi-award-fill','Promotions'],
    'badges'=>['bi-award','Badges'],
  ],
  'Videos'=>[
    'video_completions'=>['bi-collection-play-fill','Video Completions'],
    'video_manager'=>['bi-play-btn-fill','Video Manager'],
  ],
];

// ─── Determine current page ────────────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';
$found = false;
foreach ($menu as $sect=>$items) {
  if (isset($items[$page])) { $found = true; break; }
}
if (!$found) $page = 'dashboard';


// ─── Fetch admin username ─────────────────────────────────────────
$stmt = $conn->prepare("SELECT username FROM users WHERE id=?");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();

// ─── Overview counts ─────────────────────────────────────────────
$counts = [];
foreach (['students'=>'students','fees'=>'payments','videos'=>'video_completions'] as $k=>$tbl) {
  $counts[$k] = (int)$conn->query("SELECT COUNT(*) FROM `$tbl`")->fetch_row()[0];
}

// ─── Weekly leaderboard ───────────────────────────────────────────
$oneWeekAgo = (new DateTime('-7 days'))->format('Y-m-d');
$sql = "
  SELECT s.name,
         COALESCE(SUM(sh.stars),0) AS sum_stars,
         COUNT(DISTINCT a.date)    AS days_present,
         COUNT(DISTINCT hw.id)     AS hw_count,
         (COALESCE(SUM(sh.stars),0)
          +5*COUNT(DISTINCT a.date)
          +2*COUNT(DISTINCT hw.id)
         ) AS score
    FROM students s
    LEFT JOIN star_history sh ON sh.student_id=s.id AND sh.event_date>=?
    LEFT JOIN attendance a   ON a.student_id=s.id AND a.status='Present' AND a.date>=?
    LEFT JOIN homework_submissions hw ON hw.student_id=s.id AND hw.submitted_at>=?
   GROUP BY s.id
   ORDER BY score DESC
   LIMIT 5
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('sss', $oneWeekAgo, $oneWeekAgo, $oneWeekAgo);
$stmt->execute();
$leaderboard = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Panel | Rart Works</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <!-- Tailwind + Bootstrap Icons -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          colors: { primary: '#7B1FA2', accent: '#9333EA' }
        }
      }
    };
    if (localStorage.getItem('rw-dark') === 'true') {
      document.documentElement.classList.add('dark');
    }
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>

  <style>
    /* Glassy sidebar */
    #sidebar {
      backdrop-filter: blur(12px);
      background: rgba(255,255,255,0.4);
    }
    .dark #sidebar {
      background: rgba(31,41,55,0.7);
    }
  </style>
</head>
<body class="flex flex-col md:flex-row h-screen bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">

  <!-- Mobile Topbar -->
  <header class="flex items-center justify-between md:hidden bg-primary text-white px-4 py-3 shadow">
    <button id="mobileToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Admin Panel</span>
    <button id="darkToggleMobile"><i id="darkIconMobile" class="bi bi-moon-stars text-2xl"></i></button>
  </header>

  <!-- Sidebar -->
  <aside id="sidebar"
         class="fixed inset-y-0 left-0 w-64 transform -translate-x-full md:translate-x-0 transition-transform shadow-lg overflow-auto">
    <div class="p-4">
      <img src="../../assets/icons/icon-512.png" alt="Logo" class="w-28 mb-6 mx-auto">
      <?php foreach ($menu as $sect => $items): ?>
        <div class="mb-4">
          <div class="uppercase text-xs text-gray-500 dark:text-gray-400 mb-2 px-3"><?=htmlspecialchars($sect)?></div>
          <?php foreach ($items as $key => [$icon, $label]): 
            $active = $page === $key ? 'bg-accent text-white' : 'hover:bg-gray-200 dark:hover:bg-gray-700';
          ?>
            <a href="?page=<?=$key?>"
               class="flex items-center px-3 py-2 mb-1 rounded-lg <?=$active?>">
              <i class="bi <?=$icon?> mr-2"></i><?=htmlspecialchars($label)?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <a href="/artovue/logout.php"
         class="flex items-center px-3 py-2 mt-4 text-red-600 hover:bg-red-600 hover:text-white rounded-lg">
        <i class="bi bi-box-arrow-right mr-2"></i> Logout
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="flex-1 flex flex-col md:ml-64 overflow-auto">

    <!-- Desktop Topbar -->
    <header class="hidden md:flex items-center justify-between bg-primary text-white px-6 py-4 shadow">
      <div class="flex items-center space-x-4">
        <img src="../../assets/icons/icon-512.png" alt="Logo" class="h-8">
        <input type="search" placeholder="Quick search…"
               class="px-3 py-2 rounded bg-white text-gray-800 placeholder-gray-500 focus:outline-none">
      </div>
      <div class="flex items-center space-x-4">
        <span><?=htmlspecialchars($adminUsername)?></span>
        <button id="darkToggle"><i id="darkIcon" class="bi bi-moon-stars text-2xl"></i></button>
      </div>
    </header>

    <main class="p-6">
      <?php if ($page === 'dashboard'): ?>
        <!-- Overview -->
        <h1 class="text-3xl font-semibold mb-6">Overview</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
          <!-- Students -->
          <div class="bg-gradient-to-r from-yellow-400 to-purple-600 text-white p-6 rounded-lg shadow">
            <i class="bi bi-people-fill text-4xl mb-2"></i>
            <div class="text-lg">Students</div>
            <div class="text-3xl font-bold"><?=$counts['students']?></div>
          </div>
          <!-- Payments -->
          <div class="bg-gradient-to-r from-yellow-400 to-purple-600 text-white p-6 rounded-lg shadow">
            <i class="bi bi-currency-rupee text-4xl mb-2"></i>
            <div class="text-lg">Payments</div>
            <div class="text-3xl font-bold"><?=$counts['fees']?></div>
          </div>
          <!-- Videos -->
          <div class="bg-gradient-to-r from-yellow-400 to-purple-600 text-white p-6 rounded-lg shadow">
            <i class="bi bi-play-btn-fill text-4xl mb-2"></i>
            <div class="text-lg">Videos Watched</div>
            <div class="text-3xl font-bold"><?=$counts['videos']?></div>
          </div>
        </div>

        <!-- Leaderboard -->
        <section>
          <h2 class="text-2xl font-semibold mb-4">This Week’s Top Performers</h2>
          <div class="bg-white dark:bg-gray-700 rounded-lg shadow overflow-hidden">
            <?php if (empty($leaderboard)): ?>
              <div class="p-4 text-center text-gray-500">No activity this week.</div>
            <?php else: foreach ($leaderboard as $i => $r): ?>
              <div class="p-4 flex justify-between items-center <?= $i < 4 ? 'border-b dark:border-gray-600' : '' ?>">
                <div><span class="font-bold"><?= $i + 1 ?>.</span> <?=htmlspecialchars($r['name'])?></div>
                <div class="flex items-center space-x-4 text-sm">
                  <span>⭐ <?=$r['sum_stars']?></span>
                  <span>📅 <?=$r['days_present']?></span>
                  <span>📝 <?=$r['hw_count']?></span>
                  <span class="font-bold"><?=$r['score']?></span>
                </div>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </section>

      <?php else: ?>
        <?php
          $sub = __DIR__ . "/{$page}.php";
          if (is_file($sub)) {
            include $sub;
          } else {
            echo "<div class='text-red-600'>Page not found: {$page}.php</div>";
          }
        ?>
      <?php endif; ?>
    </main>
  </div>

  <script>
    // Mobile sidebar toggle
    const sidebar = document.getElementById('sidebar');
  const toggle  = document.getElementById('mobileToggle');

  // Open/close via hamburger
  toggle.onclick = () => {
    sidebar.classList.toggle('-translate-x-full');
  };

  // Close when clicking outside
  document.addEventListener('click', e => {
    // if sidebar is open...
    if (!sidebar.classList.contains('-translate-x-full')) {
      // and the click is NOT inside sidebar or on the toggle...
      if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
        sidebar.classList.add('-translate-x-full');
      }
    }
  });

    // Dark mode toggle
    function setDark(on) {
      document.documentElement.classList.toggle('dark', on);
      localStorage.setItem('rw-dark', on);
      const cls = on ? 'bi-sun-fill' : 'bi-moon-stars';
      document.querySelectorAll('#darkIcon,#darkIconMobile')
              .forEach(i => i.className = 'bi ' + cls + ' text-2xl');
    }
    document.getElementById('darkToggle').onclick =
    document.getElementById('darkToggleMobile').onclick =
      () => setDark(!document.documentElement.classList.contains('dark'));
  </script>
</body>
</html>
