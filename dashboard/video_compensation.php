<?php
// ───────────────────────────────────────────────────────────────────────────────
// File: dashboard/student/video_compensation.php
// Included via index.php?page=video_compensation
// ───────────────────────────────────────────────────────────────────────────────

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
  echo '<script>
    window.location.href = "/artovue/dashboard/student/index.php?page=video_compensation";
  </script>';
  exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$user_id = intval($_SESSION['user_id'] ?? 0);
if ($user_id < 1) {
  header('Location: /artovue/login.php');
  exit;
}

// student_id lookup
$stmt = $conn->prepare("SELECT id FROM students WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id);
if (!$stmt->fetch()) {
  $_SESSION['flash_error'] = "Student record not found.";
  echo '<script>window.location.href = "/artovue/dashboard/student/index.php";</script>';
  exit;
}
$stmt->close();

// date filter
$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  $_SESSION['flash_error'] = "Invalid date format.";
   echo '<script>window.location.href = "/artovue/dashboard/student/index.php?page=compensation";</script>';
  exit;
}

// fetch video record(s) for that date + centre
$stmt = $conn->prepare("
  SELECT v.video_url
    FROM compensation_videos v
    JOIN centres c ON c.id=v.centre_id
   WHERE v.class_date = ?
     AND v.centre_id = (
       SELECT centre_id FROM students WHERE id = ?
     )
   LIMIT 1
");
$stmt->bind_param('si', $date, $student_id);
$stmt->execute();
$stmt->bind_result($videoUrl);
$hasVideo = $stmt->fetch();
$stmt->close();
?>
<div class="container my-4">
  <h2>Compensation Videos</h2>

  <?php if (! $hasVideo): ?>
    <div class="alert alert-secondary">No videos available for <?= htmlspecialchars($date) ?>.</div>
    <a href="index.php?page=compensation" class="btn btn-outline-primary">Back to Compensation</a>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Video for <?= htmlspecialchars($date) ?></h5>
        <div class="ratio ratio-16x9 mb-3">
          <iframe src="<?= htmlspecialchars($videoUrl) ?>"
                  title="Compensation video"
                  allowfullscreen
                  frameborder="0"></iframe>
        </div>
        <form method="POST" action="mark_video_watched.php">
          <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
          <button class="btn btn-success">Mark as Watched</button>
        </form>
      </div>
    </div>
  <?php endif; ?>
</div>
