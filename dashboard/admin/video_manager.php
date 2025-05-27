<?php
// dashboard/admin/video_manager.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// Fetch centres for dropdown
$centres = $conn->query("SELECT id,name FROM centres ORDER BY name");

// Handle form submission (Add / Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action']==='add') {
        // 1) Validate video fields
        $centre_id  = (int)$_POST['centre_id'];
        $class_date = $_POST['class_date'];
        $video_url  = trim($_POST['video_url']);
        if (!$centre_id || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$class_date) || !$video_url) {
            $flash = ['type'=>'danger','msg'=>'Please fill all video fields correctly.'];
        } else {
            // Insert compensation_videos
            $ins = $conn->prepare("
              INSERT INTO compensation_videos (centre_id,class_date,video_url)
              VALUES (?,?,?)
            ");
            $ins->bind_param('iss',$centre_id,$class_date,$video_url);
            $ins->execute();
            $vid = $ins->insert_id;
            $ins->close();

            // 2) Insert each quiz question
            foreach ($_POST['quiz'] ?? [] as $q) {
                $question     = trim($q['question'] ?? '');
                $options      = array_filter(array_map('trim',$q['options'] ?? []));
                $correctIndex = (int)$q['correct_index'];
                if ($question && count($options)>=2 && isset($options[$correctIndex])) {
                    $prep = $conn->prepare("
                      INSERT INTO video_quiz_questions
                        (video_id,question,options_json,correct_index)
                      VALUES (?,?,?,?)
                    ");
                    $json = json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
                    $prep->bind_param('issi',$vid,$question,$json,$correctIndex);
                    $prep->execute();
                    $prep->close();
                }
            }

            $flash = ['type'=>'success','msg'=>'Video & quiz saved!'];
        }
    }
    elseif (isset($_POST['action']) && $_POST['action']==='delete') {
        // Delete video & its quiz
        $delVid = (int)$_POST['video_id'];
        $conn->prepare("DELETE FROM video_quiz_questions WHERE video_id=?")
             ->bind_param('i',$delVid)->execute();
        $conn->prepare("DELETE FROM compensation_videos WHERE id=?")
             ->bind_param('i',$delVid)->execute();
        $flash = ['type'=>'warning','msg'=>"Video #{$delVid} removed."];
    }

    // reload to avoid dup submits
    header('Location: video_manager.php');
    exit;
}

// Fetch existing videos + their quiz counts
$videos = $conn->query("
  SELECT v.id, c.name AS centre, v.class_date,
         v.video_url,
         (SELECT COUNT(*) FROM video_quiz_questions q WHERE q.video_id=v.id) AS quiz_count
    FROM compensation_videos v
    JOIN centres c ON c.id=v.centre_id
   ORDER BY v.class_date DESC
");
?>

<div class="container-fluid px-4">
  <h1 class="mt-4">Video & Quiz Manager</h1>
  <?php if (!empty($flash)): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="row">
    <!-- Add New Video Form -->
    <div class="col-lg-5">
      <div class="card mb-4 shadow-sm">
        <div class="card-header"><strong>Add New Video + Quiz</strong></div>
        <div class="card-body">
          <form id="videoForm" method="POST">
            <input type="hidden" name="action" value="add">
            <div class="mb-3">
              <label class="form-label">Centre</label>
              <select name="centre_id" class="form-select" required>
                <option value="">-- select centre --</option>
                <?php while($c = $centres->fetch_assoc()): ?>
                  <option value="<?= $c['id'] ?>">
                    <?= htmlspecialchars($c['name']) ?>
                  </option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label">Class Date</label>
              <input name="class_date" type="date" class="form-control" required>
            </div>
            <div class="mb-3">
              <label class="form-label">YouTube URL or Embed</label>
              <input name="video_url" type="url" class="form-control" placeholder="https://youtu.be/xyz" required>
            </div>

            <hr>
            <h5>Quiz Questions</h5>
            <div id="quizContainer"></div>
            <button type="button" id="addQuestion" class="btn btn-sm btn-outline-secondary mb-3">
              + Add Question
            </button>

            <div class="d-grid">
              <button type="submit" class="btn btn-primary">Save Video & Quiz</button>
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
          <table class="table table-striped table-bordered">
            <thead class="table-dark">
              <tr>
                <th>ID</th><th>Centre</th><th>Date</th><th>Quiz #</th><th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php while($v = $videos->fetch_assoc()): ?>
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
                    <a href="edit_video.php?id=<?= $v['id'] ?>"
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
</div>

<!-- JS: dynamic quiz question blocks -->
<script>
  let qIndex = 0;
  document.getElementById('addQuestion').addEventListener('click', ()=> {
    const container = document.getElementById('quizContainer');
    const idx       = qIndex++;
    const block = document.createElement('div');
    block.className = 'card mb-3 p-3';
    block.innerHTML = `
      <div class="mb-2">
        <label class="form-label">Question</label>
        <input type="text" name="quiz[${idx}][question]" 
               class="form-control" required>
      </div>
      <div class="row">
        ${[0,1,2,3].map(i=>`
        <div class="col-md-6 mb-2">
          <input type="text" 
                 name="quiz[${idx}][options][]" 
                 class="form-control" 
                 placeholder="Option ${i+1}" required>
        </div>`).join('')}
      </div>
      <div class="mb-2">
        <label class="form-label">Correct Option</label>
        <select name="quiz[${idx}][correct_index]" 
                class="form-select" required>
          <option value="">-- select --</option>
          ${[0,1,2,3].map(i=>`
            <option value="${i}">Option ${i+1}</option>
          `).join('')}
        </select>
      </div>
    `;
    container.appendChild(block);
  });
</script>
