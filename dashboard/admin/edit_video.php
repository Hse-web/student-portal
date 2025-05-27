<?php
// dashboard/admin/edit_video.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1) Get video ID
$videoId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$videoId) {
    die("Invalid video ID.");
}

// 2) Handle POST → update video & quiz
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic fields
    $centre_id  = (int)$_POST['centre_id'];
    $class_date = $_POST['class_date'];
    $video_url  = trim($_POST['video_url']);

    // Validate
    if (
        !$centre_id ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $class_date) ||
        !$video_url
    ) {
        $_SESSION['flash'] = [
          'type' => 'danger',
          'msg'  => 'Please fill all fields correctly.'
        ];
    } else {
        // a) Update video record
        $u = $conn->prepare("
          UPDATE compensation_videos
             SET centre_id = ?, class_date = ?, video_url = ?
           WHERE id = ?
        ");
        $u->bind_param('issi', $centre_id, $class_date, $video_url, $videoId);
        $u->execute();
        $u->close();

        // b) Delete old quiz
        $conn->prepare("DELETE FROM video_quiz_questions WHERE video_id=?")
             ->bind_param('i', $videoId)
             ->execute();

        // c) Insert new quiz questions
        foreach ($_POST['quiz'] as $q) {
            $question = trim($q['question'] ?? '');
            $options  = array_filter(array_map('trim', $q['options'] ?? []));
            $correct  = (int)($q['correct_index'] ?? -1);

            if ($question && count($options) >= 2 && isset($options[$correct])) {
                $ins = $conn->prepare("
                  INSERT INTO video_quiz_questions
                    (video_id, question, options_json, correct_index)
                  VALUES (?, ?, ?, ?)
                ");
                $json = json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
                $ins->bind_param('issi', $videoId, $question, $json, $correct);
                $ins->execute();
                $ins->close();
            }
        }

        $_SESSION['flash'] = [
          'type' => 'success',
          'msg'  => 'Video & quiz updated successfully!'
        ];
        header('Location: video_manager.php');
        exit;
    }
}

// 3) Fetch centres for the dropdown
$centres = $conn->query("SELECT id,name FROM centres ORDER BY name");

// 4) Fetch existing video
$stmt = $conn->prepare("
  SELECT centre_id, class_date, video_url
    FROM compensation_videos
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $videoId);
$stmt->execute();
$stmt->bind_result($centre_id, $class_date, $video_url);
if (!$stmt->fetch()) {
    die("Video #{$videoId} not found.");
}
$stmt->close();

// 5) Fetch existing quiz questions
$questions = [];
$q = $conn->prepare("
  SELECT id, question, options_json, correct_index
    FROM video_quiz_questions
   WHERE video_id = ?
");
$q->bind_param('i', $videoId);
$q->execute();
$res = $q->get_result();
while ($row = $res->fetch_assoc()) {
    $row['options'] = json_decode($row['options_json'], true);
    $questions[]   = $row;
}
$q->close();

// 6) Flash message
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// 7) Render header + nav
include __DIR__ . '/../../templates/partials/header_admin.php';
?>

<main class="container-fluid px-4">
  <h1 class="mt-4 mb-3">Edit Video #<?= $videoId ?></h1>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <form method="POST">
        <!-- Video Details -->
        <div class="row g-3 mb-4">
          <div class="col-md-4">
            <label class="form-label">Centre</label>
            <select name="centre_id" class="form-select" required>
              <option value="">-- choose centre --</option>
              <?php while ($c = $centres->fetch_assoc()): ?>
                <option value="<?= $c['id'] ?>"
                  <?= $c['id']==$centre_id ? 'selected':'' ?>>
                  <?= htmlspecialchars($c['name']) ?>
                </option>
              <?php endwhile; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Class Date</label>
            <input 
              type="date" 
              name="class_date" 
              class="form-control" 
              value="<?= htmlspecialchars($class_date) ?>" 
              required
            >
          </div>
          <div class="col-md-4">
            <label class="form-label">YouTube URL</label>
            <input 
              type="url" 
              name="video_url" 
              class="form-control" 
              value="<?= htmlspecialchars($video_url) ?>" 
              placeholder="https://youtu.be/xyz…" 
              required
            >
          </div>
        </div>

        <!-- Quiz Questions -->
        <h5 class="mb-3">Quiz Questions</h5>
        <div id="quizContainer">
          <?php foreach ($questions as $idx => $ques): ?>
            <div class="card mb-3 p-3 question-block">
              <div class="mb-2">
                <label class="form-label">Question</label>
                <input
                  type="text"
                  name="quiz[<?= $idx ?>][question]"
                  class="form-control"
                  value="<?= htmlspecialchars($ques['question']) ?>"
                  required
                >
              </div>
              <div class="row mb-2">
                <?php foreach ($ques['options'] as $o => $opt): ?>
                  <div class="col-md-6 mb-2">
                    <input
                      type="text"
                      name="quiz[<?= $idx ?>][options][]"
                      class="form-control"
                      value="<?= htmlspecialchars($opt) ?>"
                      placeholder="Option <?= $o+1 ?>"
                      required
                    >
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="mb-2">
                <label class="form-label">Correct Option</label>
                <select
                  name="quiz[<?= $idx ?>][correct_index]"
                  class="form-select"
                  required
                >
                  <option value="">-- choose --</option>
                  <?php foreach ($ques['options'] as $o => $_): ?>
                    <option value="<?= $o ?>"
                      <?= $o === $ques['correct_index'] ? 'selected':'' ?>>
                      Option <?= $o+1 ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <button type="button" id="addQuestion" class="btn btn-sm btn-outline-secondary mb-4">
          + Add Question
        </button>

        <div class="d-grid">
          <button class="btn btn-primary">
            <i class="bi bi-save"></i> Update Video & Quiz
          </button>
        </div>
      </form>
    </div>
  </div>
</main>

<script>
// dynamically append empty quiz blocks
let nextIdx = <?= count($questions) ?>;
document.getElementById('addQuestion').addEventListener('click', () => {
  const container = document.getElementById('quizContainer');
  const idx = nextIdx++;
  container.insertAdjacentHTML('beforeend', `
    <div class="card mb-3 p-3 question-block">
      <div class="mb-2">
        <label class="form-label">Question</label>
        <input type="text" name="quiz[${idx}][question]"
               class="form-control" required>
      </div>
      <div class="row mb-2">
        ${[0,1,2,3].map(i => `
          <div class="col-md-6 mb-2">
            <input type="text" name="quiz[${idx}][options][]"
                   class="form-control"
                   placeholder="Option ${i+1}" required>
          </div>
        `).join('')}
      </div>
      <div class="mb-2">
        <label class="form-label">Correct Option</label>
        <select name="quiz[${idx}][correct_index]" class="form-select" required>
          <option value="">-- choose --</option>
          ${[0,1,2,3].map(i => `
            <option value="${i}">Option ${i+1}</option>
          `).join('')}
        </select>
      </div>
    </div>
  `);
});
</script>
