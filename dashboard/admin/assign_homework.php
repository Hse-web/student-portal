<?php
// File: dashboard/admin/assign_homework.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── Generate CSRF token ─────────────────────────────────────────────
$csrf = generate_csrf_token();

// ─── Simple rate‐limit: only allow 5 “assign” actions per 10 minutes per session
if (! isset($_SESSION['assign_history'])) {
  $_SESSION['assign_history'] = [];
}
$_SESSION['assign_history'] = array_filter(
  $_SESSION['assign_history'],
  fn($ts) => $ts > time() - 600
);

// ─── Load centre→group map ────────────────────────────────────────────
$centreGroups = [];
$res = $conn->query("
  SELECT DISTINCT centre_id, group_name
    FROM payment_plans
   ORDER BY centre_id, group_name
");
while ($r = $res->fetch_assoc()) {
  $centreGroups[$r['centre_id']][] = $r['group_name'];
}
$res->free();

// ─── Load centres dropdown ────────────────────────────────────────────
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

// ─── Handle “Delete Assignment Batch” (POST + ?action=delete) ─────────
$errors  = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[] = 'CSRF validation failed.';
  } else {
    $aid = (int)$_POST['assignment_id'];
    // fetch “before” data (for audit)
    $stmt = $conn->prepare("
      SELECT student_id, title, description, file_path
        FROM homework_assigned
       WHERE id = ?
    ");
    $stmt->bind_param('i', $aid);
    $stmt->execute();
    $before = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // delete
    $stmt = $conn->prepare("DELETE FROM homework_assigned WHERE id = ?");
    $stmt->bind_param('i', $aid);
    $stmt->execute();
    if ($stmt->affected_rows) {
      if (function_exists('log_audit')) {
        log_audit(
          $conn,
          $_SESSION['user_id'],
          'DELETE',
          'homework_assigned',
          $aid,
          $before ?: []
        );
      }
      $success = 'Assignment batch deleted.';
    } else {
      $errors[] = 'Nothing was deleted.';
    }
    $stmt->close();
  }
}

// ─── Handle “Assign New Homework” (POST + ?action=assign) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
  // 1) CSRF
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[] = 'CSRF validation failed.';
  }
  // 2) Rate‐limit
  if (count($_SESSION['assign_history']) >= 5) {
    $errors[] = 'Too many assignments in the last 10 minutes.';
  }

  // 3) Sanitize & validate inputs
  $centre_id   = (int)($_POST['centre_id']   ?? 0);
  $group_name  = trim($_POST['group_name']   ?? '');
  $title       = trim($_POST['title']        ?? '');
  $description = trim($_POST['description']  ?? '');
  $file_path   = null;

  if ($centre_id < 1)      $errors[] = 'Pick a centre.';
  if ($group_name === '')  $errors[] = 'Pick a group.';
  if ($title === '')       $errors[] = 'Enter a title.';
  if ($description === '') $errors[] = 'Enter a description.';

  // 4) Handle attachment upload (optional)
  if (! empty($_FILES['attachment']['name'])) {
    $f     = $_FILES['attachment'];
    $types = ['application/pdf','image/jpeg','image/png','image/jpg'];
    if ($f['error'] === 0 && in_array($f['type'], $types, true)) {
      $dir = __DIR__ . '/../../uploads/homework_attachments/';
      if (! is_dir($dir)) mkdir($dir, 0755, true);
      $fn = bin2hex(random_bytes(8)) . '_' . basename($f['name']);
      if (move_uploaded_file($f['tmp_name'], "$dir$fn")) {
        $file_path = "uploads/homework_attachments/$fn";
      } else {
        $errors[] = 'Attachment upload failed.';
      }
    } else {
      $errors[] = 'Attachment must be PDF, JPG or PNG.';
    }
  }

  // 5) If no errors so far → find matching students & insert one record per student
  if (empty($errors)) {
    $stmt = $conn->prepare("
      SELECT id FROM students
       WHERE centre_id = ? AND group_name = ?
    ");
    $stmt->bind_param('is', $centre_id, $group_name);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
    $stmt->close();

    if (empty($ids)) {
      $errors[] = 'No students found in that group.';
    } else {
      $ins = $conn->prepare("
        INSERT INTO homework_assigned
          (student_id, date_assigned, title, description, file_path)
        VALUES (?, NOW(), ?, ?, ?)
      ");
      foreach ($ids as $sid) {
        $ins->bind_param('isss', $sid, $title, $description, $file_path);
        $ins->execute();
        $newId = $ins->insert_id;
        if (function_exists('log_audit')) {
          log_audit(
            $conn,
            $_SESSION['user_id'],
            'INSERT',
            'homework_assigned',
            $newId,
            [
              'student_id'  => $sid,
              'title'       => $title,
              'description' => $description,
              'file_path'   => $file_path,
            ]
          );
        }
      }
      $ins->close();
      $_SESSION['assign_history'][] = time();
      $success = 'Assigned to ' . count($ids) . ' student(s).';
    }
  }
}

// ─── Fetch “Existing Assignments” summary ────────────────────────────
$assignments = [];
$q = "
  SELECT
    DATE_FORMAT(ha.date_assigned,'%d %b %Y') AS date,
    c.name                                    AS centre,
    s.group_name                              AS `group`,
    ha.title,
    COUNT(*)                                  AS student_count,
    ha.file_path                              AS attachment,
    MIN(ha.id)                                AS example_id
  FROM homework_assigned AS ha
  JOIN students           AS s  ON s.id         = ha.student_id
  JOIN centres            AS c  ON c.id         = s.centre_id
  GROUP BY
    ha.date_assigned,
    c.name,
    s.group_name,
    ha.title,
    ha.file_path
  ORDER BY ha.date_assigned DESC
";
$res = $conn->query($q);
while ($r = $res->fetch_assoc()) {
  $assignments[] = $r;
}
$res->free();
?>
<div class="max-w-4xl mx-auto bg-white p-6 rounded-lg shadow space-y-6">
  <h2 class="text-2xl font-bold">📘 Assign Homework</h2>

  <?php if ($errors): ?>
    <div class="bg-red-100 text-red-800 p-4 rounded space-y-1">
      <?php foreach ($errors as $e): ?>
        <div>• <?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
    </div>
  <?php elseif ($success): ?>
    <div class="bg-green-100 text-green-800 p-4 rounded">
      <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action"     value="assign">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <label class="block">
        <span class="text-gray-700">Centre</span>
        <select
          id="centre"
          name="centre_id"
          class="mt-1 block w-full border-gray-300 rounded focus:ring focus:border-admin-primary"
          required>
          <option value="">— Select —</option>
          <?php foreach ($centres as $c): ?>
            <option
              value="<?= $c['id'] ?>"
              <?= ((int)($_POST['centre_id'] ?? 0) === $c['id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="block">
        <span class="text-gray-700">Group</span>
        <select
          id="group"
          name="group_name"
          class="mt-1 block w-full border-gray-300 rounded focus:ring focus:border-admin-primary"
          required>
          <option value="">— Select —</option>
        </select>
      </label>
    </div>

    <label class="block">
      <span class="text-gray-700">Title</span>
      <input
        type="text"
        name="title"
        class="mt-1 block w-full border-gray-300 rounded focus:ring focus:border-admin-primary"
        value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
        required>
    </label>

    <label class="block">
      <span class="text-gray-700">Description</span>
      <textarea
        name="description"
        rows="3"
        class="mt-1 block w-full border-gray-300 rounded focus:ring focus:border-admin-primary"
        required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </label>

    <label class="block">
      <span class="text-gray-700">Attachment (PDF/JPG/PNG)</span>
      <input
        type="file"
        name="attachment"
        accept=".pdf,.jpeg,.jpg,.png"
        class="mt-1 block w-full border-gray-300 rounded focus:ring focus:border-admin-primary">
    </label>

    <button
      type="submit"
      class="px-4 py-2 bg-admin-primary text-white rounded hover:opacity-90 transition">
      Assign
    </button>
  </form>

  <hr class="my-4" />
  <h3 class="text-xl font-semibold">Existing Assignments</h3>

  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left">Date</th>
          <th class="p-2 text-left">Centre</th>
          <th class="p-2 text-left">Group</th>
          <th class="p-2 text-left">Title</th>
          <th class="p-2 text-center"># Students</th>
          <th class="p-2 text-center">File</th>
          <th class="p-2 text-center">Delete</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($assignments)): ?>
          <tr>
            <td colspan="7" class="py-6 text-center text-gray-500">
              No assignments yet.
            </td>
          </tr>
        <?php else: foreach ($assignments as $a): ?>
          <tr>
            <td class="p-2"><?= $a['date'] ?></td>
            <td class="p-2"><?= htmlspecialchars($a['centre']) ?></td>
            <td class="p-2"><?= htmlspecialchars($a['group']) ?></td>
            <td class="p-2"><?= htmlspecialchars($a['title']) ?></td>
            <td class="p-2 text-center"><?= $a['student_count'] ?></td>
            <td class="p-2 text-center">
              <?= $a['attachment']
                 ? "<a href=\"/{$a['attachment']}\" target=\"_blank\" class=\"text-admin-primary hover:underline\">Download</a>"
                 : '—' ?>
            </td>
            <td class="p-2 text-center">
              <form method="post" class="inline" onsubmit="return confirm('Delete this batch?')">
                <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
                <input type="hidden" name="action"        value="delete">
                <input type="hidden" name="assignment_id" value="<?= $a['example_id'] ?>">
                <button type="submit" class="text-red-600 hover:text-red-800">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // dynamic “Group” dropdown based on the selected Centre
  const cfg    = <?= json_encode($centreGroups, JSON_HEX_TAG) ?>;
  const preC   = "<?= addslashes($_POST['centre_id']   ?? '') ?>";
  const preG   = "<?= addslashes($_POST['group_name']  ?? '') ?>";
  const elC    = document.getElementById('centre');
  const elG    = document.getElementById('group');

  function refreshGroups(){
    elG.innerHTML = '<option value="">— Select —</option>';
    (cfg[elC.value] || []).forEach(g => {
      let o = document.createElement('option');
      o.value = o.text = g;
      if (g === preG) o.selected = true;
      elG.appendChild(o);
    });
  }

  elC.addEventListener('change', refreshGroups);
  window.addEventListener('DOMContentLoaded', () => {
    if (preC) elC.value = preC;
    refreshGroups();
  });
</script>
