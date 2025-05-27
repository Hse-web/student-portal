<?php
// dashboard/student/video_compensation.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
require_role('student');

$user_id    = $_SESSION['user_id'];
$class_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $class_date)) {
    die("Invalid or missing date.");
}

// 1) Fetch student + centre
$stmt = $conn->prepare("
  SELECT s.id, s.name, u.centre_id
    FROM students s
    JOIN users    u ON u.id = s.user_id
   WHERE u.id = ?
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $studentName, $centre_id);
$stmt->fetch();
$stmt->close();

// Only A/B get video flow
if ($centre_id === 3) {
    die("Live make-ups only for Centre C.");
}

// 2) Lookup video
$stmt = $conn->prepare("
  SELECT id, video_url
    FROM compensation_videos
   WHERE centre_id = ?
     AND class_date = ?
");
$stmt->bind_param('is', $centre_id, $class_date);
$stmt->execute();
$stmt->bind_result($video_id, $video_url);
if (!$stmt->fetch()) {
    die("No compensation video found for $class_date.");
}
$stmt->close();

// 3) Attendance flag check
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
$hasRow = $stmt->fetch();
$stmt->close();

$alreadyWatched = $hasRow && (int)$isVideoComp === 1;

// 4) Load quiz questions
$questions = [];
$q = $conn->prepare("
  SELECT id, question, options_json, correct_index
    FROM video_quiz_questions
   WHERE video_id = ?
");
$q->bind_param('i', $video_id);
$q->execute();
$res = $q->get_result();
while ($r = $res->fetch_assoc()) {
    $r['options'] = json_decode($r['options_json'], true);
    $questions[]  = $r;
}
$q->close();

// 5) Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $alreadyWatched) {
    // Validate quiz answers
    foreach ($questions as $ques) {
        $sel = $_POST["q{$ques['id']}"] ?? -1;
        if ((int)$sel !== $ques['correct_index']) {
            $_SESSION['video_flash'] = "One or more answers are incorrect. Please try again.";
            header("Location: ?date=$class_date");
            exit;
        }
    }

    // Record completion for audit
    $c = $conn->prepare("
      INSERT INTO video_completions (student_id, video_id)
      VALUES (?, ?)
    ");
    $c->bind_param('ii', $student_id, $video_id);
    $c->execute();
    $c->close();

    // Mark attendance compensated + video flag
    $u = $conn->prepare("
      UPDATE attendance
         SET status         = 'Compensation',
             is_compensation = 1,
             is_video_comp   = 1
       WHERE student_id = ?
         AND date       = ?
    ");
    $u->bind_param('is', $student_id, $class_date);
    $u->execute();
    $u->close();

    $_SESSION['video_flash'] = "✅ Quiz passed—your attendance has been credited!";
    header("Location: ?date=" . urlencode($class_date));
    exit;
}

// 6) Prepare flash & menu
$message    = $_SESSION['video_flash'] ?? '';
unset($_SESSION['video_flash']);

$menu = [
  ['url' => 'attendance.php',             'label' => 'Attendance'],
  ['url' => "video_compensation.php?date={$class_date}", 'label' => 'Video Make-up'],
];
?>
<?php
include __DIR__.'/../../templates/partials/header_student.php';
?>

<div class="card mb-4">
  <div class="card-body">
    <h3 class="card-title">Video Make-up for <?= htmlspecialchars($class_date) ?></h3>

    <?php if ($message): ?>
      <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($alreadyWatched): ?>
      <div class="alert alert-success">
        <i class="bi bi-check-circle-fill"></i>
        You’ve already completed this video and been credited.
      </div>
    <?php else: ?>
      <!-- Responsive YouTube embed -->
      <div class="ratio ratio-16x9 mb-4">
        <iframe
          src="<?= htmlspecialchars($video_url) ?>"
          title="Compensation Video"
          allow="autoplay; encrypted-media"
          allowfullscreen
        ></iframe>
      </div>

      <!-- Quiz form -->
      <form method="POST">
        <?php foreach ($questions as $idx => $ques): ?>
          <div class="mb-3">
            <label class="form-label">
              <?= ($idx+1) ?>. <?= htmlspecialchars($ques['question']) ?>
            </label>
            <?php foreach ($ques['options'] as $optIndex => $opt): ?>
              <div class="form-check">
                <input
                  class="form-check-input"
                  type="radio"
                  name="q<?= $ques['id'] ?>"
                  id="q<?= $ques['id'] ?>_<?= $optIndex ?>"
                  value="<?= $optIndex ?>"
                  required
                >
                <label
                  class="form-check-label"
                  for="q<?= $ques['id'] ?>_<?= $optIndex ?>"
                >
                  <?= htmlspecialchars($opt) ?>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <button class="btn btn-success">
          <i class="bi bi-play-fill"></i> Submit Quiz & Credit
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../templates/partials/footer.php'; ?>
