<?php
require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
list($totalDue, $nextDue) = compute_student_due($conn, $studentId);

// fetch student name
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// fetch star count
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) $starCount = 0;
$stmt->close();

// fetch unread notifications count
$stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE student_id = ? AND is_read = 0");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($notifUnreadCount);
$stmt->fetch();
$stmt->close();

$menu = [
  'dashboard'       => ['bi-house-fill','Home'],
  'homework'        => ['bi-journal-text','Homework'],
  'attendance'      => ['bi-calendar-check','Attendance'],
  'stars'           => ['bi-stars','Stars'],
  'announcements'   => ['bi-megaphone-fill','Announcements'],
  'student_payment' => ['bi-currency-rupee','Payment'],
  'notifications'   => ['bi-bell-fill','Notifications'],
  'progress'        => ['bi-bar-chart-fill','Progress'],
];
$page = $_GET['page'] ?? 'dashboard';
if (!array_key_exists($page, $menu)) $page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Student Portal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#fff3e0; }
    .topbar { background:#e91e63; color:#fff; padding:.75rem 1.5rem; }
    .sidebar { background:#fff; min-height:100vh; border-right:1px solid #ccc; }
    .nav-link.active { background:#ff9800; color:#fff; font-weight:600; }
    .card-hover:hover { transform:translateY(-5px); box-shadow:0 8px 16px rgba(0,0,0,.15); }
  </style>
</head>
<body>
  <div class="topbar d-flex justify-content-between">
    <div>🎨 Hello, <?= htmlspecialchars($studentName) ?></div>
    <a href="../../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
  </div>
  <div class="container-fluid">
    <div class="row">
      <nav class="col-md-2 d-none d-md-block sidebar p-3">
        <ul class="nav flex-column">
          <?php foreach($menu as $k => [$icon, $label]): ?>
            <li class="nav-item mb-2">
              <a href="?page=<?= $k ?>" class="nav-link <?= $page === $k ? 'active' : '' ?>">
                <i class="bi <?= $icon ?> me-2"></i><?= $label ?>
                <?php if ($k === 'notifications' && $notifUnreadCount > 0): ?>
                  <span class="badge bg-danger ms-1"><?= $notifUnreadCount ?></span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <main class="col-md-10 py-4">
        <?php if ($page === 'dashboard'): ?>
          <div class="row g-4 mb-4">
            <div class="col-6 col-md-4">
              <div class="card text-center bg-warning text-white card-hover p-3">
                <i class="bi bi-stars fs-1"></i>
                <h5 class="mt-2">Stars</h5>
                <h3><?= htmlspecialchars($starCount) ?></h3>
              </div>
            </div>
            <div class="col-6 col-md-4">
              <div class="card text-center bg-success text-white card-hover p-3">
                <i class="bi bi-currency-rupee fs-1"></i>
                <h5 class="mt-2">Next Payment Due</h5>
                <h3>₹<?= number_format($totalDue, 0) ?></h3>
                <small>Due: <?= htmlspecialchars($nextDue) ?></small>
              </div>
            </div>
          </div>
        <?php else:
          $sub = __DIR__ . "/{$page}.php";
          if (file_exists($sub)) include $sub;
          else echo '<div class="alert alert-warning">Page not found.</div>';
        endif; ?>
      </main>
    </div>
  </div>

<audio id="notifSound" preload="auto">
  <source src="https://www.soundjay.com/button/sounds/beep-07.mp3" type="audio/mpeg">
</audio>

<script>
let unlocked = false;
document.addEventListener('click', () => {
  const a = document.getElementById('notifSound');
  if (!unlocked) {
    a.play().then(() => {
      a.pause();
      unlocked = true;
      console.log('🔓 Sound unlocked');
    });
  }
});

async function checkNotifications() {
  const res = await fetch('api/unread_notifications.php');
  const data = await res.json();

  if (data.unread_count > 0 && unlocked) {
    const a = document.getElementById('notifSound');
    a.volume = 1.0;
    a.play().catch(err => console.warn('🔇 Audio blocked:', err));
  }
}
setInterval(checkNotifications, 60000);
checkNotifications();
</script>
</body>
</html>
