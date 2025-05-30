<?php
// dashboard/student/index.php

// ─── Boot & Auth ─────────────────────────────────────────────
require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// ─── 1) Identify student ──────────────────────────────────────
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}

// ─── 2) Compute fees ───────────────────────────────────────────
list($totalDue, $nextDue) = compute_student_due($conn, $studentId);

// ─── 3) Fetch student name ────────────────────────────────────
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// ─── 4) Star count ─────────────────────────────────────────────
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) $starCount = 0;
$stmt->close();

// ─── 5) Unread notifications ──────────────────────────────────
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM notifications 
   WHERE student_id = ? 
     AND is_read = 0
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($notifUnreadCount);
$stmt->fetch();
$stmt->close();

// ─── 6) Sidebar & header ──────────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';
$menu = [
  ['url'=>'index.php','icon'=>'bi-house-fill','label'=>'Home'],
  ['url'=>'attendance.php','icon'=>'bi-calendar-check','label'=>'Attendance'],
  ['url'=>'compensation.php','icon'=>'bi-clock-history','label'=>'Compensation'],
  ['url'=>'homework.php','icon'=>'bi-journal-text','label'=>'Homework'],
  ['url'=>'stars.php','icon'=>'bi-stars','label'=>'Stars'],
  ['url'=>'notifications.php','icon'=>'bi-bell-fill','label'=>'Notifications'],
  ['url'=>'progress.php','icon'=>'bi-bar-chart-fill','label'=>'Progress'],
];

// bring in the student header (opens <html> / <body> and sidebar)
include __DIR__ . '/../../templates/partials/header_student.php';
?>

<?php if ($page === 'dashboard'): ?>
  <div class="row g-4 mb-4">
    <div class="col-6 col-md-4">
      <div class="card text-center bg-warning text-white p-3 card-hover">
        <i class="bi bi-stars fs-1"></i>
        <h5 class="mt-2">Stars</h5>
        <h3><?= $starCount ?></h3>
      </div>
    </div>
    <div class="col-6 col-md-4">
      <div class="card text-center bg-success text-white p-3 card-hover">
        <i class="bi bi-currency-rupee fs-1"></i>
        <h5 class="mt-2">Next Payment Due</h5>
        <h3>₹<?= number_format($totalDue,0) ?></h3>
        <small>Due: <?= htmlspecialchars($nextDue ?: 'n/a') ?></small>
      </div>
    </div>
  </div>
<?php else: 
  // include sub‐page if it exists
  $sub = __DIR__ . "/{$page}.php";
  if (is_file($sub)) {
      include $sub;
  } else {
      echo '<div class="alert alert-warning">Page not found.</div>';
  }
endif; ?>

<!-- (optional) notification beep script -->
<audio id="notifSound" preload="auto">
  <source 
    src="https://www.soundjay.com/button/sounds/beep-07.mp3"
    type="audio/mpeg">
</audio>
<script>
  // unlock on first click
  let unlocked = false;
  document.addEventListener('click',()=> {
    if(!unlocked){
      let a = document.getElementById('notifSound');
      a.play().then(()=>a.pause());
      unlocked = true;
    }
  });

  async function checkNotifications(){
    let res = await fetch('api/unread_notifications.php');
    let data = await res.json();
    if(data.unread_count > 0 && unlocked){
      let a = document.getElementById('notifSound');
      a.play().catch(()=>{});
    }
  }
  setInterval(checkNotifications,60000);
  checkNotifications();
</script>

<?php
// close out the layout (<main>, container, body, html)
include __DIR__ . '/../../templates/partials/footer.php';
