<?php
// File: dashboard/student/index.php

// ─── 1) Bootstrapping + “student” Auth Guard ───────────────────────
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';
// ─── 2) Identify logged‐in student (student_id was set at login) ───
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}

// ─── 3) Compute fees for this student ─────────────────────────────
[$totalDue, $nextDue] = compute_student_due($conn, $studentId);

// ─── 4) Fetch student’s name ───────────────────────────────────────
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// ─── 5) Fetch star count (if your “stars” table exists) ────────────
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) {
    $starCount = 0;
}
$stmt->close();

// ─── 6) Fetch unread notifications ─────────────────────────────────
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

// ─── 7) Determine which “sub‐page” we’re on ────────────────────────
$page = $_GET['page'] ?? 'dashboard';

// ─── 8) Render the shared student header + sidebar + topbar ───────
render_student_header('Student Dashboard');
?>

<!-- ─── 9) Page Content ────────────────────────────────────────────── -->

<?php if ($page === 'dashboard'): ?>
  <div class="container-fluid">
    <div class="row g-4 mb-4">
      <!-- Stars Tile -->
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card text-center bg-warning text-white p-4 shadow-sm">
          <div class="card-body">
            <i class="bi bi-stars fs-1"></i>
            <h5 class="mt-2">Stars Earned</h5>
            <h2 class="display-5"><?= $starCount ?></h2>
          </div>
        </div>
      </div>
      <!-- Next Due Tile -->
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card text-center bg-success text-white p-4 shadow-sm">
          <div class="card-body">
            <i class="bi bi-currency-rupee fs-1"></i>
            <h5 class="mt-2">Outstanding Balance</h5>
            <h2 class="display-5">₹<?= number_format($totalDue, 0) ?></h2>
            <p class="mb-0"><small>Next Due: <?= htmlspecialchars($nextDue ?: 'N/A') ?></small></p>
          </div>
        </div>
      </div>
      <!-- Unread Notifications Tile -->
      <div class="col-12 col-md-6 col-lg-4">
        <div class="card text-center bg-info text-white p-4 shadow-sm">
          <div class="card-body">
            <i class="bi bi-bell-fill fs-1"></i>
            <h5 class="mt-2">New Notifications</h5>
            <h2 class="display-5"><?= $notifUnreadCount ?></h2>
          </div>
        </div>
      </div>
    </div>
  </div>

<?php else: 
  // ─── Not “dashboard”—include the corresponding sub‐page if it exists ───
  $subpageFile = __DIR__ . "/{$page}.php";
  if (is_file($subpageFile)) {
      include $subpageFile;
  } else {
      echo '<div class="container"><div class="alert alert-warning">Page not found.</div></div>';
  }
endif; ?>

<!-- ─── 10) Optional notification beep + JS poll ────────────────────── -->
<audio id="notifSound" preload="auto">
  <source src="https://www.soundjay.com/button/sounds/beep-07.mp3" type="audio/mpeg">
</audio>
<script>
let unlocked = false;
document.addEventListener('click', () => {
  if (!unlocked) {
    const a = document.getElementById('notifSound');
    a.play().then(() => a.pause());
    unlocked = true;
  }
});
async function checkNotifications() {
  try {
    let res = await fetch('api/unread_notifications.php');
    if (!res.ok) return;
    let data = await res.json();
    if (data.unread_count > 0 && unlocked) {
      document.getElementById('notifSound').play().catch(() => {});
    }
  } catch {}
}
setInterval(checkNotifications, 60000);
checkNotifications();
</script>

<?php
// ─── 11) Render the shared student footer & closing tags ─────────────
render_student_footer();
