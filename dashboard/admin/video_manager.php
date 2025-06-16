<?php
// File: dashboard/admin/video_manager.php

/**
 * This fragment is included by index.php?page=video_manager.
 * It assumes the <head> and <body>…<main> tags are already output by the global layout.
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── 1) HANDLE POST (Add / Delete) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token (reuse same single token for both actions)
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    // — Add a new video + quiz —
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        $centre_id  = (int) ($_POST['centre_id'] ?? 0);
        $class_date = trim($_POST['class_date'] ?? '');
        $video_url  = trim($_POST['video_url'] ?? '');

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
            if (! $stmt) {
                die("Prepare failed (insert video): " . $conn->error);
            }
            $stmt->bind_param('iss', $centre_id, $class_date, $video_url);
            $stmt->execute();
            $videoId = $stmt->insert_id;
            $stmt->close();

            // Insert quiz questions (if any)
            foreach ($_POST['quiz'] ?? [] as $idx => $q) {
                $question = trim($q['question'] ?? '');
                $optionsRaw= array_filter(array_map('trim', $q['options'] ?? []));
                $correct   = (int) ($q['correct_index'] ?? -1);

                // Ensure at least two non-empty options, and the correct index exists
                if ($question && count($optionsRaw) >= 2 && isset($optionsRaw[$correct])) {
                    $prep = $conn->prepare("
                      INSERT INTO video_quiz_questions
                        (video_id, question, options_json, correct_index)
                      VALUES (?, ?, ?, ?)
                    ");
                    if (! $prep) {
                        die("Prepare failed (insert quiz): " . $conn->error);
                    }
                    $json = json_encode(array_values($optionsRaw), JSON_UNESCAPED_UNICODE);
                    $prep->bind_param('issi', $videoId, $question, $json, $correct);
                    $prep->execute();
                    $prep->close();
                }
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => 'Video & quiz saved!'
            ];
        } else {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'msg'  => 'Invalid input. Please check all fields.'
            ];
        }
    }

    // — Delete a video + its related data —
    elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $vid = (int) ($_POST['video_id'] ?? 0);

        if ($vid > 0) {
            // 1) Delete any completions
            $d1 = $conn->prepare("DELETE FROM video_completions WHERE video_id = ?");
            if (! $d1) {
                die("Prepare failed (delete completions): " . $conn->error);
            }
            $d1->bind_param('i', $vid);
            $d1->execute();
            $d1->close();

            // 2) Delete any quiz questions
            $d2 = $conn->prepare("DELETE FROM video_quiz_questions WHERE video_id = ?");
            if (! $d2) {
                die("Prepare failed (delete quizzes): " . $conn->error);
            }
            $d2->bind_param('i', $vid);
            $d2->execute();
            $d2->close();

            // 3) Finally delete the video row
            $d3 = $conn->prepare("DELETE FROM compensation_videos WHERE id = ?");
            if (! $d3) {
                die("Prepare failed (delete video): " . $conn->error);
            }
            $d3->bind_param('i', $vid);
            $d3->execute();
            $d3->close();

            $_SESSION['flash'] = [
                'type' => 'warning',
                'msg'  => "Video #{$vid} and its data have been deleted."
            ];
        } else {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'msg'  => 'Invalid video ID.'
            ];
        }
    }

    // Redirect back to the same page to avoid form‐resubmission
    header('Location: index.php?page=video_manager');
    exit;
}

// ─── 2) FETCH CENTRES FOR “Add” FORM ─────────────────────────────────
$centreStmt = $conn->prepare("SELECT id, name FROM centres ORDER BY name");
if (! $centreStmt) {
    die("Prepare failed (fetch centres): " . $conn->error);
}
$centreStmt->execute();
$centreRes = $centreStmt->get_result();
$centreStmt->close();

// ─── 3) FETCH EXISTING VIDEOS + QUIZ COUNTS ────────────────────────
$videosRes = $conn->query("
  SELECT
    v.id,
    c.name       AS centre,
    v.class_date,
    (
      SELECT COUNT(*) 
        FROM video_quiz_questions q 
       WHERE q.video_id = v.id
    ) AS quiz_count
  FROM compensation_videos v
  JOIN centres c ON c.id = v.centre_id
  ORDER BY v.class_date DESC
");
if (! $videosRes) {
    die("Query failed (fetch videos): " . $conn->error);
}

// ─── 4) GRAB & CLEAR ANY FLASH MESSAGE ─────────────────────────────
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ─── 5) GENERATE A CSRF TOKEN (one‐time) ───────────────────────────
$csrf = generate_csrf_token();

// ─── 6) OUTPUT THE “Video Manager” FRAGMENT ────────────────────────
?>
<div class="container-fluid px-4">
  <h1 class="mt-4 text-2xl font-semibold text-gray-800">🎥 Video & Quiz Manager</h1>

  <?php if ($flash): ?>
    <div class="mt-4 p-4 rounded text-sm
        <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-700 border border-green-400'
           : ($flash['type'] === 'warning' ? 'bg-yellow-100 text-yellow-800 border border-yellow-400'
           : 'bg-red-100 text-red-700 border border-red-400') ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
    <!-- ─── Add Video + Quiz FORM ──────────────────────────────────── -->
    <div class="bg-white rounded-lg shadow p-6">
      <h2 class="text-xl font-bold mb-4">➕ Add New Video + Quiz</h2>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="add">

        <div>
          <label class="block text-gray-700 mb-1">Centre</label>
          <select 
            name="centre_id"
            required
            class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
          >
            <option value="">-- select centre --</option>
            <?php while ($c = $centreRes->fetch_assoc()): ?>
              <option value="<?= (int)$c['id'] ?>">
                <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        </div>

        <div>
          <label class="block text-gray-700 mb-1">Class Date</label>
          <input 
            type="date"
            name="class_date"
            required
            class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
          >
        </div>

        <div>
          <label class="block text-gray-700 mb-1">YouTube URL</label>
          <input
            type="url"
            name="video_url"
            placeholder="https://youtu.be/xyz"
            required
            class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
          >
        </div>

        <hr class="my-4">

        <h3 class="text-lg font-medium mb-2">Quiz Questions</h3>
        <div id="quizContainer" class="space-y-4"></div>

        <button
          type="button"
          id="addQuestion"
          class="inline-flex items-center px-3 py-1 border border-gray-300 rounded hover:bg-gray-100"
        >
          + Add Question
        </button>

        <div>
          <button
            type="submit"
            class="w-full bg-admin-primary text-white py-2 rounded hover:bg-opacity-90 transition"
          >
            Save Video & Quiz
          </button>
        </div>
      </form>
    </div>

    <!-- ─── Existing Videos TABLE ───────────────────────────────────── -->
    <div class="bg-white rounded-lg shadow p-6 overflow-x-auto">
      <h2 class="text-xl font-bold mb-4">📋 Existing Videos</h2>
      <table class="min-w-full bg-white divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">ID</th>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Centre</th>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Date</th>
            <th class="px-4 py-2 text-center text-sm font-medium text-gray-700">Quiz #</th>
            <th class="px-4 py-2 text-center text-sm font-medium text-gray-700">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if ($videosRes->num_rows === 0): ?>
            <tr>
              <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                No videos found.
              </td>
            </tr>
          <?php else: ?>
            <?php while ($v = $videosRes->fetch_assoc()): ?>
              <tr>
                <td class="px-4 py-2 text-sm text-gray-700"><?= (int)$v['id'] ?></td>
                <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($v['centre']) ?></td>
                <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($v['class_date']) ?></td>
                <td class="px-4 py-2 text-center text-sm text-gray-700"><?= (int)$v['quiz_count'] ?></td>
                <td class="px-4 py-2 text-center flex justify-center space-x-2">
                  <!-- Delete button -->
                  <form method="post" onsubmit="return confirm('Delete video #<?= (int)$v['id'] ?>?')">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action"     value="delete">
                    <input type="hidden" name="video_id"   value="<?= (int)$v['id'] ?>">
                    <button 
                      type="submit"
                      class="text-red-600 hover:text-red-800"
                      title="Delete"
                    >
                      <i class="bi bi-trash-fill text-lg"></i>
                    </button>
                  </form>
                  <!-- (Optional) you mentioned edit_video.php:-->
                  <a 
                    href="index.php?page=edit_video&id=<?= (int)$v['id'] ?>"
                    class="text-gray-700 hover:text-gray-900"
                    title="Edit"
                  >
                    <i class="bi bi-pencil-fill text-lg"></i>
                  </a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ─── JS: DYNAMIC QUIZ QUESTION BLOCKS ───────────────────────────── -->
<script>
  let qIndex = 0;
  document.getElementById('addQuestion').addEventListener('click', () => {
    const container = document.getElementById('quizContainer');
    const idx = qIndex++;
    const card = document.createElement('div');
    card.className = 'bg-gray-50 rounded-lg p-4 border border-gray-200 space-y-2';
    card.innerHTML = `
      <div>
        <label class="block text-gray-700 mb-1">Question</label>
        <input type="text"
               name="quiz[${idx}][question]"
               class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
               required>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        ${[0,1,2,3].map(i => `
          <div>
            <input type="text"
                   name="quiz[${idx}][options][]"
                   class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
                   placeholder="Option ${i+1}"
                   required>
          </div>
        `).join('')}
      </div>
      <div>
        <label class="block text-gray-700 mb-1">Correct Option</label>
        <select name="quiz[${idx}][correct_index]"
                required
                class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
        >
          <option value="">-- select --</option>
          ${[0,1,2,3].map(i => `
            <option value="${i}">Option ${i+1}</option>
          `).join('')}
        </select>
      </div>
    `;
    container.appendChild(card);
  });
</script>
