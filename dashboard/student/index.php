<?php
// File: dashboard/student/index.php

// ─── 1) Bootstrap + Auth ─────────────────────────────────────────────
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';

// ─── 2) Student ID + Current Page ────────────────────────────────────
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: /student-portal/login.php');
    exit;
}
$page = $_GET['page'] ?? 'dashboard';

// ─── 3) Compute student dues ───────────────────────────────────────────
[$totalDue, $nextDue] = compute_student_due($conn, $studentId);

// ─── 4) Student name ─────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// ─── 5) Star count ────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) {
    $starCount = 0;
}
$stmt->close();

// ─── 6) Unread notifications ──────────────────────────────────────────
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM notifications 
   WHERE student_id = ? 
     AND is_read = 0
  LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($notifUnreadCount);
$stmt->fetch();
$stmt->close();

// ─── 7) Build our menu once ───────────────────────────────────────────
$studentMenu = [
  'dashboard'     => ['bi-speedometer2',   'Dashboard',        '/student-portal/dashboard/student/index.php?page=dashboard'],
  'homework'      => ['bi-journal-text',   'My Homework',      '/student-portal/dashboard/student/index.php?page=homework'],
  'attendance'    => ['bi-calendar-check', 'Attendance',       '/student-portal/dashboard/student/index.php?page=attendance'],
  'progress'      => ['bi-bar-chart-line', 'My Progress',      '/student-portal/dashboard/student/index.php?page=progress'],
  'stars'         => ['bi-star-fill',      'Stars & Rewards',  '/student-portal/dashboard/student/index.php?page=stars'],
  'student_payment'=>['bi-currency-rupee', 'Payment',          '/student-portal/dashboard/student/index.php?page=student_payment'],
  'compensation'  => ['bi-clock',          'Compensation',     '/student-portal/dashboard/student/index.php?page=compensation'],
  'profile'       => ['bi-person-circle',  'My Profile',       '/student-portal/dashboard/student/index.php?page=profile'],
  'notifications' => ['bi-bell-fill',      'Notifications',    '/student-portal/dashboard/student/index.php?page=notifications'],
  'logout'        => ['bi-box-arrow-right','Logout',           '/student-portal/logout.php'],
];

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Student Portal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <!-- Tailwind & Bootstrap & Icons -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'student-primary': '#1E40AF',
            'student-secondary': '#60A5FA'
          }
        }
      }
    };
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <style> a { text-decoration: none; } </style>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">

  <!-- Mobile Topbar -->
  <nav class="md:hidden bg-student-primary text-white p-3 flex items-center justify-between">
    <button id="mobileMenuToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Student Portal</span>
    <a href="/student-portal/logout.php"><i class="bi bi-box-arrow-right text-2xl"></i></a>
  </nav>

  <!-- Mobile Slide‐Down Menu -->
  <div id="mobileMenu" class="hidden md:hidden bg-student-secondary text-gray-900 shadow-lg">
    <ul class="flex flex-col divide-y divide-gray-200">
      <?php foreach($studentMenu as $key=>[$icon,$label,$href]): 
        $active = ($page === $key) ? 'bg-student-primary text-white' : 'hover:bg-student-primary hover:text-white';
      ?>
        <li>
          <a href="<?= $href ?>"
             class="flex items-center p-3 <?= $active ?> transition">
            <i class="bi <?= $icon ?> mr-3"></i>
            <?= htmlspecialchars($label) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="flex flex-1">

    <!-- Desktop Sidebar -->
    <aside class="hidden md:block w-64 bg-student-secondary text-gray-900 p-4">
      <div class="mb-4 border-b pb-2">
        <span class="font-bold text-lg">Student Portal</span>
      </div>
      <ul class="space-y-2">
        <?php foreach($studentMenu as $key=>[$icon,$label,$href]):
          $active = ($page === $key)
                  ? 'bg-student-primary text-white'
                  : 'hover:bg-student-primary hover:text-white';
        ?>
          <li>
            <a href="<?= $href ?>"
               class="flex items-center p-2 rounded-lg <?= $active ?> transition"
               <?= $page === $key ? 'aria-current="page"' : '' ?>>
              <i class="bi <?= $icon ?> mr-3"></i>
              <?= htmlspecialchars($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
      <!-- Desktop Topbar -->
      <header class="hidden md:flex bg-student-primary text-white px-6 py-3 justify-between">
        <div class="font-semibold"><i class="bi bi-shield-lock-fill"></i> Student Portal</div>
        <div><?= htmlspecialchars($studentName) ?></div>
      </header>

      <main class="flex-1 overflow-auto p-6">
        <?php if($page==='dashboard'): ?>
          <div class="container-fluid">
            <div class="row g-4 mb-4">
              <!-- Stars -->
              <div class="col-12 col-md-6 col-lg-4">
                <div class="card text-center bg-warning text-white p-4 shadow-sm">
                  <i class="bi bi-stars fs-1"></i>
                  <h5 class="mt-2">Stars Earned</h5>
                  <h2 class="display-5"><?= $starCount ?></h2>
                </div>
              </div>
              <!-- Balance -->
              <div class="col-12 col-md-6 col-lg-4">
                <div class="card text-center bg-success text-white p-4 shadow-sm">
                  <i class="bi bi-currency-rupee fs-1"></i>
                  <h5 class="mt-2">Outstanding Balance</h5>
                  <h2 class="display-5">₹<?= number_format($totalDue) ?></h2>
                  <p><small>Next Due: <?= htmlspecialchars($nextDue ?: 'N/A') ?></small></p>
                </div>
              </div>
              <!-- Notifications -->
              <div class="col-12 col-md-6 col-lg-4">
                <div class="card text-center bg-info text-white p-4 shadow-sm">
                  <i class="bi bi-bell-fill fs-1"></i>
                  <h5 class="mt-2">New Notifications</h5>
                  <h2 class="display-5"><?= $notifUnreadCount ?></h2>
                </div>
              </div>
            </div>
          </div>
        <?php else: 
          $sub = __DIR__ . "/{$page}.php";
          if (is_file($sub)) {
            include $sub;
          } else {
            echo '<div class="alert alert-warning">Page not found.</div>';
          }
        endif; ?>
      </main>

      <footer class="bg-student-secondary text-gray-900 text-center text-sm py-4">
        &copy; <?= date('Y') ?> Student Portal
      </footer>
    </div>
  </div>

  <!-- Bootstrap JS + Mobile Menu Toggle + Notification Beep -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // slide‐down mobile menu
    document.getElementById('mobileMenuToggle')
      .addEventListener('click', ()=> {
        document.getElementById('mobileMenu')
          .classList.toggle('hidden');
      });

    // notification sound
    let unlocked = false;
    const snd = new Audio('https://www.soundjay.com/button/sounds/beep-07.mp3');
    document.addEventListener('click', ()=> {
      if (!unlocked) {
        snd.play().then(()=>snd.pause()).catch(()=>{});
        unlocked = true;
      }
    });
    async function checkNotifications(){
      try{
        const res = await fetch('api/unread_notifications.php');
        if (!res.ok) return;
        const { unread_count } = await res.json();
        if(unread_count>0 && unlocked) snd.play().catch(()=>{});
      }catch{}
    }
    setInterval(checkNotifications,60000);
    checkNotifications();
  </script>
</body>
</html>
