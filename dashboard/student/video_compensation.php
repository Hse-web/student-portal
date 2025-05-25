<?php
// dashboard/student/video_compensation.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

$user_id    = $_SESSION['user_id'];
$class_date = $_GET['date'] ?? '';
if (!$class_date) {
    die("Invalid date.");
}

// 1) Lookup student & centre
$stmt = $conn->prepare("
  SELECT s.id, s.name, u.centre_id
    FROM students s
    JOIN users u ON u.id = s.user_id
   WHERE u.id = ?
   LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $studentName, $centre_id);
if (!$stmt->fetch()) {
    die("Student not found.");
}
$stmt->close();

// 2) Only Centres A/B get video comp here
if ($centre_id === 3) {
    die("Live make-ups only for Centre C.");
}

// 3) Fetch the video record
$stmt = $conn->prepare("
  SELECT id, video_url
    FROM compensation_videos
   WHERE centre_id = ?
     AND class_date = ?
   LIMIT 1
");
$stmt->bind_param('is', $centre_id, $class_date);
$stmt->execute();
$stmt->bind_result($video_id, $video_url);
if (!$stmt->fetch()) {
    die("No compensation video found for $class_date.");
}
$stmt->close();

// 4) Check attendance row for is_video_comp flag
$stmt = $conn->prepare("
  SELECT is_video_comp
    FROM attendance
   WHERE student_id = ?
     AND date       = ?
   LIMIT 1
");
$stmt->bind_param('is', $student_id, $class_date);
$stmt->execute();
$stmt->bind_result($isVideoComp);
$hasAttendance = $stmt->fetch();  
$stmt->close();

// If there's no attendance row at all, treat as not watched:
$alreadyWatched = $hasAttendance && (int)$isVideoComp === 1;

// 5) Handle POST only if not yet watched
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $alreadyWatched) {
    // a) Record completion (optional, for logs)
    $c = $conn->prepare("
      INSERT INTO video_completions (student_id, video_id)
      VALUES (?, ?)
    ");
    $c->bind_param('ii', $student_id, $video_id);
    $c->execute();
    $c->close();

    // b) Update that attendance row
    $u = $conn->prepare("
      UPDATE attendance
         SET status         = 'Compensation',
             is_compensation = 1,
             is_video_comp    = 1
       WHERE student_id = ?
         AND date       = ?
    ");
    $u->bind_param('is', $student_id, $class_date);
    $u->execute();
    $u->close();

    $_SESSION['video_flash'] = "✅ Thanks for watching — your attendance is now credited.";
    header("Location: video_compensation.php?date=" . urlencode($class_date));
    exit;
}

// 6) Pull flash and clear
if (isset($_SESSION['video_flash'])) {
    $message = $_SESSION['video_flash'];
    unset($_SESSION['video_flash']);
}

// 7) Build menu + name for header
$menu = [
  ['url'=>'student.php','label'=>'Dashboard'],
  ['url'=>'attendance.php','label'=>'Attendance'],
  ['url'=>'compensation.php','label'=>'Live Make-up'],
  ['url'=>"video_compensation.php?date=$class_date",'label'=>'Video Make-up'],
];
?>
<?php include __DIR__ . '/../../templates/partials/header_student.php'; ?>

<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-body">

      <h2 class="card-title">Video Make-up for <?= htmlspecialchars($class_date) ?></h2>

      <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <?php if ($alreadyWatched): ?>
        <div class="alert alert-info">
          ✅ You’ve already watched this video. Attendance credited.
        </div>
      <?php else: ?>
        <!-- YouTube container -->
        <div class="ratio ratio-16x9 mb-3">
          <div id="youtube-player"></div>
        </div>

        <!-- Mark as Watched -->
        <form method="POST">
          <button id="completeBtn" class="btn btn-primary" disabled>
            Mark as Watched
          </button>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../../templates/partials/footer.php'; ?>

<?php if (! $alreadyWatched): ?>
<script src="https://www.youtube.com/iframe_api"></script>
<script>
  let player, done=false;
  function onYouTubeIframeAPIReady() {
    const vid = "<?= htmlspecialchars($video_url.split('/').pop()) ?>";
    player = new YT.Player('youtube-player',{
      videoId: vid,
      events: { 'onStateChange': onStateChange },
      playerVars: { controls:1, modestbranding:1 }
    });
  }
  function onStateChange(e) {
    if (e.data===YT.PlayerState.PLAYING && !done) poll();
  }
  function poll() {
    const cur=player.getCurrentTime(), dur=player.getDuration();
    if (cur/dur >= .95) {
      document.getElementById('completeBtn').disabled=false;
      done=true;
    } else {
      setTimeout(poll,1000);
    }
  }
</script>
<?php endif; ?>
