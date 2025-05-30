<?php
// File: dashboard/student/video_compensation.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

// 1) Validate `date` parameter (YYYY-MM-DD)
$class_date = $_GET['date'] ?? '';
if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $class_date)) {
    // Redirect back to attendance if missing/invalid
    header('Location: attendance.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// 2) Look up student and their centre
$stmt = $conn->prepare(
    "SELECT s.id, s.name, u.centre_id
     FROM students s
     JOIN users    u ON u.id = s.user_id
     WHERE u.id = ? LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $studentName, $centre_id);
if (! $stmt->fetch()) {
    die('Student record not found.');
}
$stmt->close();

// 3) Centre C must use live make‑ups, so block here
if ($centre_id === 3) {
    header('Location: compensation.php');
    exit;
}

// 4) Fetch the compensation video for this centre & date
$stmt = $conn->prepare(
    "SELECT id, video_url
     FROM compensation_videos
     WHERE centre_id = ? AND class_date = ?
     LIMIT 1"
);
$stmt->bind_param('is', $centre_id, $class_date);
$stmt->execute();
$stmt->bind_result($video_id, $video_url);
if (! $stmt->fetch()) {
    // no video available
    $stmt->close();
    include __DIR__ . '/../../templates/partials/header_student.php';
    echo '<div class="container mt-4"><div class="alert alert-warning">No make‑up video for ' . htmlspecialchars($class_date) . '.</div></div>';
    include __DIR__ . '/../../templates/partials/footer.php';
    exit;
}
$stmt->close();

// 5) Check if already credited in attendance
$stmt = $conn->prepare(
    "SELECT is_video_comp
     FROM attendance
     WHERE student_id = ? AND date = ? LIMIT 1"
);
$stmt->bind_param('is', $student_id, $class_date);
$stmt->execute();
$stmt->bind_result($isComp);
$hasRow = $stmt->fetch();
$stmt->close();
$already = $hasRow && (bool)$isComp;

// 6) Load quiz questions
$questions = [];
$q = $conn->prepare(
    "SELECT id, question, options_json, correct_index
     FROM video_quiz_questions
     WHERE video_id = ?"
);
$q->bind_param('i', $video_id);
$q->execute();
$res = $q->get_result();
while ($r = $res->fetch_assoc()) {
    $r['options'] = json_decode($r['options_json'], true);
    $questions[]  = $r;
}
$q->close();

// 7) Handle POST (quiz submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $already) {
    // verify each question
    foreach ($questions as $ques) {
        $answer = $_POST['q'.$ques['id']] ?? null;
        if ((int)$answer !== (int)$ques['correct_index']) {
            $_SESSION['video_flash'] = 'One or more answers are incorrect. Please try again.';
            header("Location: video_compensation.php?date=$class_date");
            exit;
        }
    }

    // mark completion
    $c = $conn->prepare("INSERT INTO video_completions (student_id, video_id) VALUES (?, ?)");
    $c->bind_param('ii', $student_id, $video_id);
    $c->execute();
    $c->close();

    // update attendance record
    $u = $conn->prepare(
        "UPDATE attendance
         SET status='Compensation', is_compensation=1, is_video_comp=1
         WHERE student_id = ? AND date = ?"
    );
    $u->bind_param('is', $student_id, $class_date);
    $u->execute();
    $u->close();

    $_SESSION['video_flash'] = '✅ Quiz passed—attendance credited!';
    header("Location: video_compensation.php?date=$class_date");
    exit;
}

// 8) Flash message
$flash = $_SESSION['video_flash'] ?? '';
unset($_SESSION['video_flash']);

// 9) Render page
include __DIR__ . '/../../templates/partials/header_student.php';
?>

<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <h2>Make‑up Video on <?= htmlspecialchars($class_date) ?></h2>

      <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <?php if ($already): ?>
        <div class="alert alert-success">✅ You have already completed this make‑up.</div>
      <?php else: ?>
        <!-- Embed YouTube fullscreen -->
        <div class="ratio ratio-16x9 mb-3">
          <iframe
            src="<?= htmlspecialchars($video_url) ?>"
            allow="autoplay; encrypted-media"
            allowfullscreen
          ></iframe>
        </div>

        <!-- Quiz form -->
        <?php if (count($questions)): ?>
        <form method="POST">
          <?php foreach ($questions as $i => $q): ?>
            <div class="mb-3">
              <strong><?= $i+1 ?>. <?= htmlspecialchars($q['question']) ?></strong>
              <?php foreach ($q['options'] as $idx => $opt): ?>
                <div class="form-check">
                  <input
                    class="form-check-input"
                    type="radio"
                    name="q<?= $q['id'] ?>"
                    id="q<?= $q['id'] . '_' . $idx ?>"
                    value="<?= $idx ?>"
                    required
                  >
                  <label for="q<?= $q['id'] . '_' . $idx ?>" class="form-check-label">
                    <?= htmlspecialchars($opt) ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <button type="submit" class="btn btn-primary">Submit Quiz & Credit</button>
        </form>
        <?php else: ?>
          <div class="alert alert-warning">No quiz configured for this video.</div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../templates/partials/footer.php'; ?>