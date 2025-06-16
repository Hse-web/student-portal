<?php
// File: dashboard/student/index.php

// 1) Load bootstrap + auth + helpers
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/functions.php';

// 2) Identify student & page
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: /artovue/login.php');
    exit;
}
$page = $_GET['page'] ?? 'dashboard';

// 3) Compute dues
[$totalDue, $nextDue] = compute_student_due($conn, $studentId);

// 4) Fetch student name
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// 5) Fetch star count
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) $starCount = 0;
$stmt->close();

// 6) Unread notifications
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM notifications 
   WHERE student_id = ? 
     AND is_read = 0
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($notifUnread);
$stmt->fetch();
$stmt->close();

// 7) Build menu
$studentMenu = [
  'dashboard'       => ['bi-speedometer2',   'Dashboard',       '?page=dashboard'],
  'homework'        => ['bi-journal-text',   'My Homework',     '?page=homework'],
  'attendance'      => ['bi-calendar-check', 'Attendance',      '?page=attendance'],
  'progress'        => ['bi-bar-chart-line', 'My Progress',     '?page=progress'],
  'stars'           => ['bi-star-fill',      'Stars & Rewards', '?page=stars'],
  'student_payment' => ['bi-currency-rupee', 'Payment',         '?page=student_payment'],
  'compensation'    => ['bi-clock',          'Compensation',    '?page=compensation'],
  'profile'         => ['bi-person-circle',  'My Profile',      '?page=profile'],
  'notifications'   => ['bi-bell-fill',      'Notifications',   '?page=notifications'],
  'logout'          => ['bi-box-arrow-right','Logout',          '/artovue/logout.php'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Student Portal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: { extend: {
        colors: {
          'student-primary':'#1E40AF',
          'student-secondary':'#60A5FA'
        }
      }}
    };
  </script>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"/>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet"/>
  <style> a { text-decoration: none; } </style>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">

  <!-- Toast container -->
  <div aria-live="polite" aria-atomic="true"
       class="position-fixed top-0 end-0 p-3"
       style="z-index: 1080;">
    <div id="toast-container"></div>
  </div>

  <!-- Mobile Topbar -->
  <nav class="md:hidden bg-student-primary text-white p-3 flex items-center justify-between">
    <button id="mobileMenuToggle"><i class="bi bi-list text-2xl"></i></button>
    <span class="font-semibold">Student Portal</span>
    <div class="flex items-center space-x-3">
      <!-- notification bell -->
      <a href="?page=notifications" class="position-relative text-white">
        <i class="bi bi-bell-fill text-2xl"></i>
        <?php if ($notifUnread > 0): ?>
          <span
            class="badge bg-danger position-absolute top-0 start-100 translate-middle">
            <?= $notifUnread ?>
          </span>
        <?php endif; ?>
      </a>
      <!-- logout -->
      <a href="/artovue/logout.php"><i class="bi bi-box-arrow-right text-2xl"></i></a>
    </div>
  </nav>

  <!-- Mobile Menu -->
  <div id="mobileMenu" class="hidden md:hidden bg-student-secondary text-gray-900 shadow-lg">
    <ul class="flex flex-col divide-y divide-gray-200">
      <?php foreach($studentMenu as $key=>[$icon,$label,$href]):
        $active = ($page === $key)
                ? 'bg-student-primary text-white'
                : 'hover:bg-student-primary hover:text-white';
      ?>
        <li>
          <a href="<?= $href ?>"
             class="flex items-center p-3 <?= $active ?>">
            <i class="bi <?= $icon ?> mr-3"></i><?= htmlspecialchars($label) ?>
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
               class="flex items-center p-2 rounded-lg <?= $active ?>"
               <?= $page === $key ? 'aria-current="page"' : '' ?>>
              <i class="bi <?= $icon ?> mr-3"></i><?= htmlspecialchars($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
      <!-- Desktop Topbar -->
      <header class="hidden md:flex bg-student-primary text-white px-6 py-3 justify-between">
        <div class="font-semibold">
          <i class="bi bi-shield-lock-fill me-2"></i>Student Portal
        </div>
        <div class="flex items-center space-x-4">
          <!-- notification bell -->
          <a href="?page=notifications" class="position-relative text-white">
            <i class="bi bi-bell-fill text-2xl"></i>
            <?php if ($notifUnread > 0): ?>
              <span
                class="badge bg-danger position-absolute top-0 start-100 translate-middle">
                <?= $notifUnread ?>
              </span>
            <?php endif; ?>
          </a>
          <!-- student name -->
          <span><?= htmlspecialchars($studentName) ?></span>
          <!-- logout -->
          <a href="/artovue/logout.php"
             class="border border-white rounded px-2 hover:bg-white hover:text-gray-800 transition">
            Logout
          </a>
        </div>
      </header>

      <main class="flex-1 overflow-auto p-6">
        <?php if ($page === 'dashboard'): ?>

          <!-- ── Art Journey ─────────────────────────────────────────────────────── -->
          <?php
          $currentId = null; $nextId = null;

          // current
          $q = $conn->prepare("
            SELECT art_group_id
              FROM student_promotions
             WHERE student_id=? AND is_applied=1
             ORDER BY effective_date DESC
             LIMIT 1
          ");
          $q->bind_param('i', $studentId);
          $q->execute();
          $q->bind_result($tmp);
          if ($q->fetch()) $currentId = $tmp;
          $q->close();

          // next
          $q = $conn->prepare("
            SELECT art_group_id
              FROM student_promotions
             WHERE student_id=? AND is_applied=0
             ORDER BY effective_date ASC
             LIMIT 1
          ");
          $q->bind_param('i', $studentId);
          $q->execute();
          $q->bind_result($tmp);
          if ($q->fetch()) $nextId = $tmp;
          $q->close();

          // all groups
          $groups = $conn
            ->query("SELECT id,label FROM art_groups ORDER BY sort_order")
            ->fetch_all(MYSQLI_ASSOC);

          $currentIdx = array_search($currentId, array_column($groups,'id'));
          $nextIdx    = array_search($nextId,    array_column($groups,'id'));

          $journey = [];
          foreach ($groups as $i => $g) {
            if ($currentIdx !== false && $i < $currentIdx) {
              $status = 'completed';
            } elseif ($i === $currentIdx) {
              $status = 'current';
            } elseif ($i === $nextIdx) {
              $status = 'upcoming';
            } else {
              $status = 'locked';
            }
            $journey[] = ['label'=>$g['label'],'status'=>$status];
          }
          ?>

          <div class="bg-white p-6 rounded-2xl shadow-xl mb-8 relative overflow-visible">
            <h3 class="text-3xl font-extrabold text-center mb-6">Art Journey</h3>
            <svg class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none" aria-hidden="true">
              <path d="M100,150 C250,0 400,200 550,50 S800,200 900,100"
                    stroke="#E5E7EB" stroke-width="4" fill="none"/>
            </svg>
            <div class="relative z-10 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-6 px-4">
              <?php
              $map = [
                'completed' => ['bg-green-50','border-green-400','✅','Completed','green'],
                'current'   => ['bg-blue-50','border-blue-400','✏️','In Progress','blue'],
                'upcoming'  => ['bg-yellow-50','border-yellow-400','🎨','Next','yellow'],
                'locked'    => ['bg-gray-50','border-gray-300','🔒','Locked','gray'],
              ];
              foreach ($journey as $step):
                [$bg,$border,$emoji,$badgeText,$clr] = $map[$step['status']];
              ?>
                <div class="p-4 rounded-xl border-2 <?= $bg ?> <?= $border ?>
                            flex flex-col items-center text-center shadow-sm hover:shadow-md transition">
                  <div class="text-4xl"><?= $emoji ?></div>
                  <div class="mt-3 font-medium text-lg"><?= htmlspecialchars($step['label']) ?></div>
                  <div class="mt-2 px-2 py-1 bg-<?= $clr ?>-100 text-<?= $clr ?>-800
                              rounded-full text-xs font-semibold">
                    <?= $badgeText ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="container-fluid">
            <div class="row g-4 mb-4">
              <div class="col-12 col-md-4">
                <div class="card text-center bg-warning text-white p-4 shadow-sm">
                  <i class="bi bi-stars fs-1"></i>
                  <h5 class="mt-2">Stars</h5>
                  <h2 class="display-5"><?= $starCount ?></h2>
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="card text-center bg-success text-white p-4 shadow-sm">
                  <i class="bi bi-currency-rupee fs-1"></i>
                  <h5 class="mt-2">Due</h5>
                  <h2 class="display-5">₹<?= number_format($totalDue) ?></h2>
                  <small>Next: <?= htmlspecialchars($nextDue ?: 'N/A') ?></small>
                </div>
              </div>
              <div class="col-12 col-md-4">
                <div class="card text-center bg-info text-white p-4 shadow-sm">
                  <i class="bi bi-bell-fill fs-1"></i>
                  <h5 class="mt-2">Notifications</h5>
                  <h2 class="display-5"><?= $notifUnread ?></h2>
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

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Notification‐polling code must run after bootstrap.bundle.js has loaded
    const ding = new Audio('/assets/bell-notification.mp3');

    async function pollNotifications() {
      try {
        const res   = await fetch('/artovue/dashboard/includes/fetch_notifications.php');
        if (!res.ok) throw new Error('Network response was not ok');
        const notes = await res.json();
        for (const n of notes) {
          showToast(n);
          await fetch('/artovue/dashboard/includes/mark_read.php', {
            method:  'POST',
            headers: { 'Content-Type':'application/json' },
            body:    JSON.stringify({ id: n.id })
          });
        }
      } catch (err) {
        console.error('Notification poll failed:', err);
      }
    }

    function showToast({ title, message }) {
      const container = document.getElementById('toast-container');
      const el = document.createElement('div');
      el.className = 'toast align-items-center text-white bg-primary border-0 mb-2';
      el.setAttribute('role', 'alert');
      el.setAttribute('aria-live', 'assertive');
      el.setAttribute('aria-atomic', 'true');
      el.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">
            <strong>${title}</strong><br>${message}
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto"
                  data-bs-dismiss="toast"></button>
        </div>`;
      container.appendChild(el);
      const toast = new bootstrap.Toast(el, { delay: 8000 });
      toast.show();
      ding.play().catch(() => {});
    }

    document.addEventListener('DOMContentLoaded', () => {
      pollNotifications();
      setInterval(pollNotifications, 15000);
      // mobile menu toggle
      document.getElementById('mobileMenuToggle')
        .addEventListener('click', () => {
          document.getElementById('mobileMenu').classList.toggle('hidden');
        });
    });
  </script>
</body>
</html>
