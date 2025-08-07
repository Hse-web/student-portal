<?php
// File: dashboard/student/video_compensation.php
// Included via index.php?page=video_compensation

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
  echo '<script>window.location.href = "/artovue/dashboard/student/index.php?page=video_compensation";</script>';
  exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// 1) Identify the logged-in student and their centre
$user_id = intval($_SESSION['user_id'] ?? 0);
if ($user_id < 1) {
  header('Location: /artovue/login.php');
  exit;
}
$stmt = $conn->prepare(
  "SELECT id, centre_id FROM students WHERE user_id = ? LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $centre_id);
if (! $stmt->fetch()) {
  $_SESSION['flash_error'] = "Student record not found.";
  echo '<script>window.location.href = "/artovue/dashboard/student/index.php";</script>';
  exit;
}
$stmt->close();

// 2) Validate and fetch requested date
$date = $_GET['date'] ?? '';
if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  $_SESSION['flash_error'] = "Invalid date format.";
  echo '<script>window.location.href = "/artovue/dashboard/student/index.php?page=compensation";</script>';
  exit;
}

// 3) Lookup compensation video for that date/centre
$stmt = $conn->prepare(
  "SELECT id, video_url
   FROM compensation_videos
   WHERE class_date = ? AND centre_id = ?
   LIMIT 1"
);
$stmt->bind_param('si', $date, $centre_id);
$stmt->execute();
$stmt->bind_result($video_id, $videoUrl);
$hasVideo = $stmt->fetch();
$stmt->close();

// 4) Convert YouTube short links to embed format
if ($hasVideo && preg_match('#youtu\\.be/([A-Za-z0-9_-]+)#', $videoUrl, $m)) {
  $videoUrl = "https://www.youtube.com/embed/{$m[1]}";
}

// 5) Check if this video was already marked watched
$alreadyWatched = false;
if ($hasVideo) {
  $chk = $conn->prepare(
    "SELECT 1 FROM video_completions WHERE student_id=? AND video_id=?"
  );
  $chk->bind_param('ii', $student_id, $video_id);
  $chk->execute();
  $chk->store_result();
  $alreadyWatched = $chk->num_rows > 0;
  $chk->close();
}

// 6) Fetch history of watched videos for this student
$history = [];
$hist = $conn->prepare(
  "SELECT v.class_date, v.video_url
   FROM video_completions wc
   JOIN compensation_videos v ON v.id = wc.video_id
   WHERE wc.student_id = ?
   ORDER BY v.class_date DESC"
);
$hist->bind_param('i', $student_id);
$hist->execute();
$history = $hist->get_result()->fetch_all(MYSQLI_ASSOC);
$hist->close();
?>

<div class="container my-4">
  <h2>📹 Compensation Videos</h2>

  <?php if (! $hasVideo): ?>
    <div class="alert alert-secondary">
      No video is available for <?= htmlspecialchars($date) ?>.
    </div>
    <a href="index.php?page=compensation" class="btn btn-outline-primary">
      ← Back to Make‑Up List
    </a>

  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <h5 class="card-title">Video for <?= htmlspecialchars($date) ?></h5>
        <div class="ratio ratio-16x9 mb-3">
          <iframe src="<?= htmlspecialchars($videoUrl) ?>"
                  title="Compensation video"
                  allowfullscreen frameborder="0"></iframe>
        </div>

        <?php if ($alreadyWatched): ?>
          <div class="alert alert-success">
            ✔ You have already marked this as watched.
          </div>
        <?php else: ?>
          <form method="POST" action="mark_video_watched.php">
            <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
            <input type="hidden" name="video_id" value="<?= htmlspecialchars($video_id) ?>">
            <button class="btn btn-success">Mark as Watched</button>
          </form>
        <?php endif; ?>

      </div>
    </div>
  <?php endif; ?>

  <?php if (! empty($history)): ?>
    <div class="mt-4">
      <h4>Your Watched History</h4>
      <ul class="list-group">
        <?php foreach ($history as $v): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <?= htmlspecialchars($v['class_date']) ?>
            <a href="<?= htmlspecialchars($v['video_url']) ?>" target="_blank"
               class="btn btn-sm btn-outline-secondary">Watch Again</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>