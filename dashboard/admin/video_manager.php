<?php
// File: dashboard/admin/video_manager.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── 1) Handle POST (Add / Delete) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // — Add a new video + quiz —
    if (($_POST['action'] ?? '') === 'add') {
        $centre_id      = (int) ($_POST['centre_id']      ?? 0);
        $group_id       = (int) ($_POST['group_id']       ?? 0) ?: null;
        $class_date     = trim($_POST['class_date']      ?? '');
        $video_url_req  = trim($_POST['video_url_req']   ?? '');
        $video_url_opt  = trim($_POST['video_url_opt']   ?? '');

        // basic validation
        if (
          $centre_id > 0 &&
          preg_match('/^\d{4}-\d{2}-\d{2}$/',$class_date) &&
          filter_var($video_url_req, FILTER_VALIDATE_URL)
        ) {
            // 1) insert the video row
            $stmt = $conn->prepare("
              INSERT INTO compensation_videos
                (centre_id, group_id, class_date, video_url, video_url_optional)
              VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
              'iisss',
              $centre_id,
              $group_id,
              $class_date,
              $video_url_req,
              $video_url_opt
            );
            $stmt->execute();
            $videoId = $stmt->insert_id;
            $stmt->close();

            // 2) quiz insertion (unchanged)
            foreach ($_POST['quiz'] ?? [] as $idx => $q) {
                $question   = trim($q['question']   ?? '');
                $optionsRaw = array_filter(array_map('trim', $q['options'] ?? []));
                $correct    = (int)($q['correct_index'] ?? -1);

                if ($question && count($optionsRaw) >= 2 && isset($optionsRaw[$correct])) {
                    $prep = $conn->prepare("
                      INSERT INTO video_quiz_questions
                        (video_id, question, options_json, correct_index)
                      VALUES (?, ?, ?, ?)
                    ");
                    $json = json_encode(array_values($optionsRaw), JSON_UNESCAPED_UNICODE);
                    $prep->bind_param('issi', $videoId, $question, $json, $correct);
                    $prep->execute();
                    $prep->close();
                }
            }

            $_SESSION['flash'] = ['type'=>'success','msg'=>'Video & quiz saved!'];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Please fill all required fields (at least the required URL).'];
        }
    }
    // — Delete a video + its data —
    elseif (($_POST['action'] ?? '') === 'delete') {
        $vid = (int)($_POST['video_id'] ?? 0);
        if ($vid > 0) {
            foreach (['video_completions','video_quiz_questions'] as $tbl) {
                $d = $conn->prepare("DELETE FROM {$tbl} WHERE video_id=?");
                $d->bind_param('i',$vid);
                $d->execute();
                $d->close();
            }
            $d3 = $conn->prepare("DELETE FROM compensation_videos WHERE id=?");
            $d3->bind_param('i',$vid);
            $d3->execute();
            $d3->close();
            $_SESSION['flash'] = ['type'=>'warning','msg'=>"Video #{$vid} deleted."];
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid video ID.'];
        }
    }

    header('Location: index.php?page=video_manager');
    exit;
}

// ─── 2) Fetch centres & groups ────────────────────────────────────
$centreRes = $conn->query("SELECT id,name FROM centres ORDER BY name");
$groupRes  = $conn->query("SELECT id,label FROM art_groups ORDER BY sort_order");

// ─── 3) Fetch existing videos ─────────────────────────────────────
$videosRes = $conn->query("
  SELECT
    v.id,
    c.name       AS centre,
    ag.label     AS group_label,
    v.class_date,
    v.video_url         AS required_url,
    v.video_url_optional AS optional_url,
    (SELECT COUNT(*) FROM video_quiz_questions q WHERE q.video_id=v.id) AS quiz_count
  FROM compensation_videos v
  JOIN centres c      ON c.id=v.centre_id
  LEFT JOIN art_groups ag ON ag.id=v.group_id
  ORDER BY v.class_date DESC
");

// ─── 4) Flash + CSRF ──────────────────────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf  = generate_csrf_token();
?>

<div class="container-fluid px-4">
  <h1 class="mt-4 text-2xl font-semibold">🎥 Video & Quiz Manager</h1>

  <?php if($flash): ?>
    <div class="mt-4 p-4 rounded text-sm 
      <?= $flash['type']==='success' ? 'bg-green-100 text-green-700' : 
         ($flash['type']==='warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-700') ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">

    <!-- ➕ Add New Video + Quiz -->
    <div class="bg-white rounded-lg shadow p-6">
      <h2 class="text-xl font-bold mb-4">➕ Add New Video + Quiz</h2>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="add">

        <div>
          <label class="block mb-1">Centre</label>
          <select name="centre_id" required class="w-full border rounded px-3 py-2">
            <option value="">— select centre —</option>
            <?php while($c = $centreRes->fetch_assoc()): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endwhile ?>
          </select>
        </div>

        <div>
          <label class="block mb-1">Assign to Group</label>
          <select name="group_id" class="w-full border rounded px-3 py-2">
            <option value="">— all groups —</option>
            <?php while($g = $groupRes->fetch_assoc()): ?>
              <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['label']) ?></option>
            <?php endwhile ?>
          </select>
        </div>

        <div>
          <label class="block mb-1">Class Date</label>
          <input type="date" name="class_date" required class="w-full border rounded px-3 py-2">
        </div>

        <div>
          <label class="block mb-1">YouTube URL (Required)</label>
          <input type="url" name="video_url_req" placeholder="https://youtu.be/xyz" required class="w-full border rounded px-3 py-2">
        </div>

        <div>
          <label class="block mb-1">YouTube URL (Optional)</label>
          <input type="url" name="video_url_opt" placeholder="https://youtu.be/abc" class="w-full border rounded px-3 py-2">
        </div>

        <hr class="my-4">
        <h3 class="text-lg font-medium mb-2">Quiz Questions</h3>
        <div id="quizContainer" class="space-y-4"></div>
        <button type="button" id="addQuestion" class="px-3 py-1 border rounded hover:bg-gray-100">
          + Add Question
        </button>

        <div>
          <button type="submit" class="w-full bg-indigo-600 text-white py-2 rounded">
            Save Video & Quiz
          </button>
        </div>
      </form>
    </div>

    <!-- 📋 Existing Videos -->
    <div class="bg-white rounded-lg shadow p-6 overflow-x-auto">
      <h2 class="text-xl font-bold mb-4">📋 Existing Videos</h2>
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2">ID</th>
            <th class="px-4 py-2">Centre</th>
            <th class="px-4 py-2">Group</th>
            <th class="px-4 py-2">Date</th>
            <th class="px-4 py-2">Req. URL</th>
            <th class="px-4 py-2">Opt. URL</th>
            <th class="px-4 py-2 text-center">Quiz #</th>
            <th class="px-4 py-2 text-center">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if ($videosRes->num_rows === 0): ?>
            <tr><td colspan="8" class="p-4 text-center">No videos found.</td></tr>
          <?php else: ?>
            <?php while($v = $videosRes->fetch_assoc()): ?>
              <tr>
                <td class="px-4 py-2"><?= $v['id'] ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($v['centre']) ?></td>
                <td class="px-4 py-2"><?= $v['group_label'] ?: 'All' ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($v['class_date']) ?></td>
                <td class="px-4 py-2"><a href="<?= htmlspecialchars($v['required_url']) ?>" target="_blank">🔗</a></td>
                <td class="px-4 py-2">
                  <?php if($v['optional_url']): ?>
                    <a href="<?= htmlspecialchars($v['optional_url']) ?>" target="_blank">🔗</a>
                  <?php else: ?>
                    —
                  <?php endif ?>
                </td>
                <td class="px-4 py-2 text-center"><?= $v['quiz_count'] ?></td>
                <td class="px-4 py-2 text-center space-x-2">
                  <form method="post" onsubmit="return confirm('Delete #<?= $v['id'] ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="delete">
                    <input type="hidden" name="video_id"   value="<?= $v['id'] ?>">
                    <button type="submit" class="text-red-600">🗑️</button>
                  </form>
                  <a href="index.php?page=edit_video&id=<?= $v['id'] ?>" class="text-gray-700">✏️</a>
                </td>
              </tr>
            <?php endwhile ?>
          <?php endif ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
  let qIndex = 0;
  document.getElementById('addQuestion').addEventListener('click', () => {
    const container = document.getElementById('quizContainer');
    const idx = qIndex++;
    const card = document.createElement('div');
    card.className = 'bg-gray-50 rounded p-4 border space-y-2';
    card.innerHTML = `
      <label>Question</label>
      <input type="text" name="quiz[${idx}][question]" class="w-full border rounded p-2" required>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        ${[0,1,2,3].map(i=>`
          <input type="text" name="quiz[${idx}][options][]" placeholder="Option ${i+1}" class="w-full border rounded p-2" required>
        `).join('')}
      </div>
      <label>Correct Option</label>
      <select name="quiz[${idx}][correct_index]" class="w-full border rounded p-2" required>
        <option value="">— select —</option>
        ${[0,1,2,3].map(i=>`<option value="${i}">Option ${i+1}</option>`).join('')}
      </select>
    `;
    container.appendChild(card);
  });
</script>
