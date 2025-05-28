<?php
// File: dashboard/admin/video_manager.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1) Handle POST (Add / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // — Add a new video + quiz —
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $centre_id  = (int) $_POST['centre_id'];
        $class_date = $_POST['class_date'];
        $video_url  = trim($_POST['video_url']);

        if (
            $centre_id > 0 &&
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $class_date) &&
            filter_var($video_url, FILTER_VALIDATE_URL)
        ) {
            // Insert video
            $stmt = $conn->prepare("
              INSERT INTO compensation_videos
                (centre_id, class_date, video_url)
              VALUES (?, ?, ?)
            ");
            if (!$stmt) {
                die("Prepare failed (insert video): " . $conn->error);
            }
            $stmt->bind_param('iss', $centre_id, $class_date, $video_url);
            $stmt->execute();
            $videoId = $stmt->insert_id;
            $stmt->close();

            // Insert quiz questions
            foreach ($_POST['quiz'] ?? [] as $q) {
                $question = trim($q['question'] ?? '');
                $options  = array_filter(array_map('trim', $q['options'] ?? []));
                $correct  = (int) ($q['correct_index'] ?? -1);

                if ($question && count($options) >= 2 && isset($options[$correct])) {
                    $prep = $conn->prepare("
                      INSERT INTO video_quiz_questions
                        (video_id, question, options_json, correct_index)
                      VALUES (?, ?, ?, ?)
                    ");
                    if (!$prep) {
                        die("Prepare failed (insert quiz): " . $conn->error);
                    }
                    $json = json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
                    $prep->bind_param('issi', $videoId, $question, $json, $correct);
                    $prep->execute();
                    $prep->close();
                }
            }

            $_SESSION['flash'] = ['type'=>'success','msg'=>'Video & quiz saved!'];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid input.'];
        }
    }

    // — Delete a video + its related data —
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $vid = (int) $_POST['video_id'];

        // 1) Delete any completions
        $d1 = $conn->prepare("DELETE FROM video_completions WHERE video_id = ?");
        if (!$d1) {
            die("Prepare failed (delete completions): " . $conn->error);
        }
        $d1->bind_param('i', $vid);
        $d1->execute();
        $d1->close();

        // 2) Delete any quiz questions
        $d2 = $conn->prepare("DELETE FROM video_quiz_questions WHERE video_id = ?");
        if (!$d2) {
            die("Prepare failed (delete quizzes): " . $conn->error);
        }
        $d2->bind_param('i', $vid);
        $d2->execute();
        $d2->close();

        // 3) Finally delete the video row
        $d3 = $conn->prepare("DELETE FROM compensation_videos WHERE id = ?");
        if (!$d3) {
            die("Prepare failed (delete video): " . $conn->error);
        }
        $d3->bind_param('i', $vid);
        $d3->execute();
        $d3->close();

        $_SESSION['flash'] = ['type'=>'warning','msg'=>"Video #{$vid} deleted."];
    }

    // Redirect back to the manager page
    header('Location: index.php?page=video_manager');
    exit;
}

// 2) Fetch centres for the “Add” form
$centreStmt = $conn->prepare("SELECT id, name FROM centres ORDER BY name");
if (!$centreStmt) {
    die("Prepare failed (fetch centres): " . $conn->error);
}
$centreStmt->execute();
$centreRes = $centreStmt->get_result();
$centreStmt->close();

// 3) Fetch existing videos + quiz counts
$videosRes = $conn->query("
  SELECT
    v.id,
    c.name AS centre,
    v.class_date,
    (SELECT COUNT(*) 
       FROM video_quiz_questions q 
      WHERE q.video_id = v.id
    ) AS quiz_count
  FROM compensation_videos v
  JOIN centres c ON c.id = v.centre_id
  ORDER BY v.class_date DESC
");
if (!$videosRes) {
    die("Query failed (fetch videos): " . $conn->error);
}

// 4) Grab and clear any flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<!-- MAIN CONTENT: index.php already includes header_admin.php -->
<main class="container-fluid px-4">
  <h1 class="mt-4">Video & Quiz Manager</h1>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- Add Video + Quiz -->
    <div class="col-lg-5">
      <div class="card mb-4 shadow-sm">
        <div class="card-header"><strong>Add New Video + Quiz</strong></div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="add">

            <div class="mb-3">
              <label class="form-label">Centre</label>
              <select name="centre_id" class="form-select" required>
                <option value="">-- select centre --</option>
                <?php while($c = $centreRes->fetch_assoc()): ?>
                  <option value="<?= $c['id'] ?>">
                    <?= htmlspecialchars($c['name']) ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Class Date</label>
              <input type="date" name="class_date" class="form-control" required>
            </div>

            <div class="mb-3">
              <label class="form-label">YouTube URL</label>
              <input type="url" name="video_url"
                     class="form-control"
                     placeholder="https://youtu.be/xyz"
                     required>
            </div>

            <hr>
            <h5>Quiz Questions</h5>
            <div id="quizContainer"></div>
            <button type="button"
                    id="addQuestion"
                    class="btn btn-sm btn-outline-secondary mb-3">
              + Add Question
            </button>

            <div class="d-grid">
              <button type="submit" class="btn btn-primary">
                Save Video & Quiz
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Existing Videos Table -->
    <div class="col-lg-7">
      <div class="card mb-4 shadow-sm">
        <div class="card-header"><strong>Existing Videos</strong></div>
        <div class="card-body table-responsive">
          <table class="table table-striped table-bordered mb-0">
            <thead class="table-dark">
              <tr>
                <th>ID</th>
                <th>Centre</th>
                <th>Date</th>
                <th>Quiz #</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($v = $videosRes->fetch_assoc()): ?>
                <tr>
                  <td><?= $v['id'] ?></td>
                  <td><?= htmlspecialchars($v['centre']) ?></td>
                  <td><?= htmlspecialchars($v['class_date']) ?></td>
                  <td><?= $v['quiz_count'] ?></td>
                  <td>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Delete video #<?= $v['id'] ?>?')">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="video_id" value="<?= $v['id'] ?>">
                      <button class="btn btn-sm btn-danger">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                    <a href="index.php?page=edit_video&id=<?= $v['id'] ?>"
                       class="btn btn-sm btn-secondary">
                      <i class="bi bi-pencil"></i>
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</main>

<!-- JS: dynamic quiz question blocks -->
<script>
  let qIndex = 0;
  document.getElementById('addQuestion').addEventListener('click', () => {
    const container = document.getElementById('quizContainer');
    const idx = qIndex++;
    const card = document.createElement('div');
    card.className = 'card mb-3 p-3';
    card.innerHTML = `
      <div class="mb-2">
        <label class="form-label">Question</label>
        <input type="text" name="quiz[${idx}][question]"
               class="form-control" required>
      </div>
      <div class="row mb-2">
        ${[0,1,2,3].map(i => `
          <div class="col-md-6 mb-2">
            <input type="text"
                   name="quiz[${idx}][options][]"
                   class="form-control"
                   placeholder="Option ${i+1}"
                   required>
          </div>
        `).join('')}
      </div>
      <div class="mb-2">
        <label class="form-label">Correct Option</label>
        <select name="quiz[${idx}][correct_index]"
                class="form-select" required>
          <option value="">-- select --</option>
          ${[0,1,2,3].map(i => `
            <option value="${i}">Option ${i+1}</option>
          `).join('')}
        </select>
      </div>
    `;
    container.append(card);
  });
</script>
