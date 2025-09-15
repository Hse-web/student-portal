<?php
// File: dashboard/student/index.php

// 1) Load bootstrap + auth + helpers
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';
date_default_timezone_set('Asia/Kolkata');
// 2) Identify student & page
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: /artovue/login.php');
    exit;
}
$page = $_GET['page'] ?? 'dashboard';

// 3) Compute dues  ✅ associative-safe
$dueInfo   = compute_student_due($conn, $studentId);  // assoc array
$totalDue  = (float)($dueInfo['total'] ?? 0);
$nextDue   = null;
if (!empty($dueInfo['due_date']) && $dueInfo['due_date'] !== '0000-00-00') {
    $ts = strtotime($dueInfo['due_date']);
    if ($ts) {
        $nextDue = date('M j, Y', $ts);
    }
}

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
// if a badge was just earned, queue a JS toast
$badgeToast = null;
if (!empty($_SESSION['badge_earned'])) {
  $badgeToast = $_SESSION['badge_earned'];
  unset($_SESSION['badge_earned']);
}
// ────────────────────────────────────────────────────────────
// 7× Auto-award badges based on actions
// ────────────────────────────────────────────────────────────
$award = function(string $slug, bool $condition) use ($conn, $studentId) {
    // look up badge by slug
    $row = $conn->query(
      "SELECT id, label FROM badges WHERE slug = '{$slug}' LIMIT 1"
    )->fetch_assoc();
    if (! $row || ! $condition) return;
    $badgeId = (int)$row['id'];
    $badgeLabel = $conn->real_escape_string($row['label']);

    // only insert once
    $exists = $conn->query("
      SELECT 1 FROM user_badges
       WHERE student_id = {$studentId}
         AND badge_id    = {$badgeId}
      LIMIT 1
    ")->num_rows;
    if ($exists) return;

    // award it
    $stmt = $conn->prepare("
      INSERT INTO user_badges (student_id,badge_id) 
      VALUES (?,?)
    ");
    $stmt->bind_param('ii', $studentId, $badgeId);
    $stmt->execute();
    $stmt->close();

    // immediate toast notification
    echo "<script>showToast('🎉 Badge Earned!','You just unlocked “{$badgeLabel}”!');</script>";
};

// 7.1) First Compensation — watched at least one make-up video
$watchedCount = (int)$conn->query(
  "SELECT COUNT(*) FROM video_completions WHERE student_id={$studentId}"
)->fetch_row()[0];
$award('first_comp', $watchedCount > 0);

// 7.2) Consistency King — 5-day present streak
$rows = $conn->query("
  SELECT date
    FROM attendance
   WHERE student_id={$studentId}
     AND status='Present'
   ORDER BY date DESC
   LIMIT 5
")->fetch_all(MYSQLI_NUM);
$streakDates = array_column($rows, 0);
$isStreak = count($streakDates)===5
         && (new DateTime($streakDates[0]))
               ->diff(new DateTime(end($streakDates)))
               ->days === 4;
$award('consistency', $isStreak);

// 8) Fetch earned badges
$stmt = $conn->prepare("
  SELECT b.icon, b.label,b.tier, ub.earned_at
    FROM user_badges ub
    JOIN badges      b ON b.id   = ub.badge_id
   WHERE ub.student_id = ?
   ORDER BY ub.earned_at DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$badges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 9) Build menu
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
  'coming_soon'   => ['bi-bell-fill',      'Coming Soon',   '?page=coming_soon'],
  'logout'          => ['bi-box-arrow-right','Logout',          '/artovue/logout.php'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Student Portal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#6b21a8">
  <link rel="icon" href="../../assets/icons/icon-512.png">

  <!-- Service Worker -->
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/service-worker.js')
        .catch(()=>{});
    }
  </script>
  <script>
// after Bootstrap JS loads:
document.addEventListener('DOMContentLoaded', () => {
  <?php if ($badgeToast): 
     $lbl = json_encode($badgeToast['label']);
     $desc= json_encode($badgeToast['description']);
  ?>
  // show badge toast
  const el = document.createElement('div');
  el.className = 'toast align-items-center text-white bg-success border-0 mb-2';
  el.setAttribute('role','alert');
  el.setAttribute('aria-live','assertive');
  el.setAttribute('aria-atomic','true');
  el.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">
        🏅 You earned a badge: <strong>${<?= $lbl ?>}</strong><br>
        ${<?= $desc ?>}
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto"
              data-bs-dismiss="toast"></button>
    </div>`;
  document.getElementById('toast-container').appendChild(el);
  new bootstrap.Toast(el, { delay: 7000 }).show();
  <?php endif; ?>
});
</script>

  <!-- Tailwind + custom palette -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'primary'   : '#6b21a8',
            'secondary' : '#9333ea',
            'accent'    : '#fbbf24',
            'success'   : '#10b981',
            'danger'    : '#ef4444',
          }
        }
      }
    };
  </script>

  <!-- Bootstrap + Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>

  <style>
    /* Utility overrides */
    .bg-secondary { background-color: #9333ea !important; }
    .bg-primary   { background-color: #6b21a8 !important; }
    a { text-decoration: none !important; }
  </style>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">

  <!-- Toast container -->
  <div aria-live="polite" aria-atomic="true"
       class="position-fixed top-0 end-0 p-3" style="z-index:1080;">
    <div id="toast-container"></div>
  </div>

  <!-- MOBILE TOPBAR -->
  <nav class="md:hidden bg-primary text-white p-3 flex items-center justify-between shadow-md">
    <button id="mobileMenuToggle"><i class="bi bi-list text-2xl"></i></button>
    <img src="../../assets/icons/icon-512.png" alt="Artovue" class="h-8"/>
    <div class="flex items-center space-x-3">
      <a href="?page=notifications" class="position-relative">
        <i class="bi bi-bell-fill text-2xl"></i>
        <?php if ($notifUnread>0): ?>
          <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
            <?= $notifUnread ?>
          </span>
        <?php endif; ?>
      </a>
      <a href="/artovue/logout.php"><i class="bi bi-box-arrow-right text-2xl"></i></a>
    </div>
  </nav>

  <!-- MOBILE MENU -->
  <div id="mobileMenu" class="hidden bg-secondary text-white shadow-lg">
    <ul class="divide-y divide-gray-200">
      <?php foreach($studentMenu as $key=>[$icon,$label,$href]):
        $cls = $page===$key ? 'bg-primary' : 'hover:bg-primary/80';
      ?>
        <li>
          <a href="<?= $href ?>" class="flex items-center p-3 <?= $cls ?>">
            <i class="bi <?= $icon ?> me-3"></i><?= $label ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="flex flex-1">

    <!-- DESKTOP SIDEBAR -->
    <aside class="hidden md:block w-64 bg-secondary text-white p-4 shadow-lg">
      <div class="mb-6 text-center">
        <img src="../../assets/icons/icon-512.png" alt="Artovue" class="w-32 mx-auto mb-4"/>
        <div class="font-bold text-xl">Student Portal</div>
      </div>
      <ul class="space-y-2">
        <?php foreach($studentMenu as $key=>[$icon,$label,$href]):
          $cls = $page===$key ? 'bg-primary text-white' : 'hover:bg-primary/80';
        ?>
          <li>
            <a href="<?= $href ?>" class="flex items-center p-2 rounded-lg <?= $cls ?>">
              <i class="bi <?= $icon ?> me-3"></i><?= $label ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <div class="flex-1 flex flex-col">

      <!-- DESKTOP TOPBAR -->
      <header class="hidden md:flex bg-primary text-white px-6 py-3 justify-between shadow-md">
        <div class="flex items-center space-x-4">
          <img src="../../assets/icons/icon-512.png" class="h-8" alt="Artovue"/>
          <?php
            $h = (int)date('H');
            $g = $h<12?'Good morning':($h<16?'Good afternoon':'Good evening');
          ?>
          <span class="font-semibold"><?= $g ?>, <?= htmlspecialchars($studentName) ?>!</span>
        </div>
        <div class="flex items-center space-x-4">
          <a href="?page=notifications" class="position-relative">
            <i class="bi bi-bell-fill text-2xl"></i>
            <?php if($notifUnread>0):?>
              <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
                <?= $notifUnread ?>
              </span>
            <?php endif;?>
          </a>
          <a href="/artovue/logout.php"
             class="border border-white px-3 py-1 rounded hover:bg-white hover:text-gray-800 transition">
            Logout
          </a>
        </div>
      </header>

      <!-- MAIN CONTENT -->
      <main class="flex-1 overflow-auto p-6">
        <?php if ($page==='dashboard'): ?>

          <!-- Art Journey (unchanged) -->
          <?php
            // ... your existing journey logic here ...
          ?>

          <!-- Summary Cards -->
          <div class="container-fluid mb-6">
            <div class="row g-4">
              <div class="col-12 col-md-4">
                <div class="card text-center bg-success text-white shadow-md">
                  <div class="card-body py-4">
                    <i class="bi bi-stars fs-1"></i>
                    <h5 class="mt-2">Stars</h5>
                    <h2 class="display-5"><?= $starCount ?></h2>
                  </div>
                </div>
              </div>
              <!--<div class="col-12 col-md-4">-->
              <!--  <div class="card text-center bg-accent text-white shadow-md">-->
              <!--    <div class="card-body py-4">-->
              <!--      <i class="bi bi-currency-rupee fs-1"></i>-->
              <!--      <h5 class="mt-2">Due</h5>-->
              <!--      <h2 class="display-5">₹<?= number_format($totalDue) ?></h2>-->
              <!--      <small>Next: <?= htmlspecialchars($nextDue ?: 'N/A') ?></small>-->
              <!--    </div>-->
              <!--  </div>-->
              <!--</div>-->
              <div class="col-12 col-md-4">
                <div class="card text-center bg-info text-white shadow-md">
                  <div class="card-body py-4">
                    <i class="bi bi-bell-fill fs-1"></i>
                    <h5 class="mt-2">Notifications</h5>
                    <h2 class="display-5"><?= $notifUnread ?></h2>
                  </div>
                </div>
              </div>
            </div>

<!-- ── Badges & Gamification ────────────────────────────────────────── -->
<h4 class="mt-8 mb-4 text-2xl font-semibold text-gray-800">Your Badges</h4>
<?php if (empty($badges)): ?>
  <div class="alert alert-info">
    Attend <strong>5 days in a row</strong> to earn your first
    <em>Consistency King</em> badge!
  </div>
<?php else: ?>
  <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
    <?php foreach ($badges as $b): 
      // pick border color by tier
      $borderColor = match($b['tier']) {
        'gold'   => 'border-yellow-400',
        'silver' => 'border-gray-400',
        default  => 'border-yellow-700',
      };
    ?>
      <div class="bg-white rounded-xl shadow-md p-6 text-center border-4 <?= $borderColor ?>">
        <i class="bi <?= htmlspecialchars($b['icon']) ?> text-4xl text-student-primary mb-4"></i>
        <div class="text-lg font-medium text-gray-800">
          <?= htmlspecialchars($b['label']) ?>
        </div>
        <?php if (!empty($b['earned_at'])): ?>
          <div class="mt-2 text-sm text-gray-500">
            Earned <?= (new DateTime($b['earned_at']))->format('M j, Y') ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

        <?php else:
          $sub = __DIR__ . "/{$page}.php";
          if (is_file($sub)) {
            include $sub;
          } else {
            echo '<div class="alert alert-warning">Page not found.</div>';
          }
        endif; ?>
      </main>

      <footer class="bg-secondary text-white text-center py-4">
        &copy; <?= date('Y') ?> <strong>Artovue</strong> · Powered by Rart Works
      </footer>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Mobile menu toggle
    document.getElementById('mobileMenuToggle')
      .addEventListener('click', () => {
        document.getElementById('mobileMenu').classList.toggle('hidden');
      });

    // Simple toast helper
    function showToast(title, msg) {
      const c = document.getElementById('toast-container');
      const el = document.createElement('div');
      el.className = 'toast align-items-center text-white bg-primary border-0 mb-2';
      el.setAttribute('role','alert');
      el.setAttribute('aria-live','assertive');
      el.setAttribute('aria-atomic','true');
      el.innerHTML = `
        <div class="d-flex">
          <div class="toast-body">
            <strong>${title}</strong><br>${msg}
          </div>
          <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>`;
      c.appendChild(el);
      new bootstrap.Toast(el,{ delay:5000 }).show();
    }
  </script>
  <?php if (!empty($_SESSION['badge_earned'])):
    $toast = $_SESSION['badge_earned'];
    // clear it so it only shows once
    unset($_SESSION['badge_earned']);
?>
<script>
// create a Bootstrap toast element
document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('toast-container');
  const toastEl = document.createElement('div');
  toastEl.className = 'toast align-items-center text-white bg-success border-0 mb-2';
  toastEl.setAttribute('role','alert');
  toastEl.setAttribute('aria-live','assertive');
  toastEl.setAttribute('aria-atomic','true');
  toastEl.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">
        <strong>🎉 You unlocked: <?= htmlspecialchars($toast['label'], ENT_QUOTES) ?></strong><br>
        <?= htmlspecialchars($toast['description'], ENT_QUOTES) ?>
      </div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto"
              data-bs-dismiss="toast"></button>
    </div>`;
  container.appendChild(toastEl);

  const toast = new bootstrap.Toast(toastEl, { delay: 6000 });
  toast.show();
});
</script>
<?php endif; ?>
</body>
</html>
