<?php
// File: dashboard/admin/progress.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ────────────────────────────────────────────────────────────────────
// FLASH MESSAGES: retrieve + clear any prior flash messages
// ────────────────────────────────────────────────────────────────────
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ─── Generate CSRF for “delete” links (if needed) ────────────────────
$csrf = generate_csrf_token();

// ─── Load students dropdown ──────────────────────────────────────────
$students = $conn
  ->query("SELECT id,name FROM students ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

// ─── Load remark templates ───────────────────────────────────────────
$templates = [];
$res = $conn->query("SELECT id,category_key,text FROM remark_templates");
while ($row = $res->fetch_assoc()) {
  $templates[$row['category_key']][] = $row;
}

// ─── Define grades & categories ──────────────────────────────────────
$grades = [
  '1' => 'Needs Improvement',
  '2' => 'Average',
  '3' => 'Good',
  '4' => 'Very Good',
  '5' => 'Excellent',
];
$cats = [
  'hand_control'     => 'Hand Control',
  'coloring_shading' => 'Coloring & Shading',
  'observations'     => 'Observations',
  'temperament'      => 'Temperament',
  'attendance'       => 'Attendance',
  'homework'         => 'Homework',
];
$remarkCols = [
  'hand_control'     => 'hc_remark',
  'coloring_shading' => 'cs_remark',
  'observations'     => 'obs_remark',
  'temperament'      => 'temp_remark',
  'attendance'       => 'att_remark',
  'homework'         => 'hw_remark',
];

// ─── Handle “delete a progress record” via GET?delete=### ───────────
if (isset($_GET['delete'])) {
  $delId = (int) $_GET['delete'];
  $stmt = $conn->prepare("DELETE FROM progress WHERE id = ?");
  $stmt->bind_param('i', $delId);
  $stmt->execute();
  $stmt->close();

  // ─── FLASH: success message for deletion
  $_SESSION['flash_success'] = "Progress record #{$delId} deleted.";
  header('Location: index.php?page=progress');
  exit;
}

// ─── If editing, load that record ───────────────────────────────────
$edit = null;
if (isset($_GET['id'])) {
  $eid = (int) $_GET['id'];
  $stmt = $conn->prepare("SELECT * FROM progress WHERE id = ? LIMIT 1");
  $stmt->bind_param('i', $eid);
  $stmt->execute();
  $edit = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// ─── Handle form submission (add or update) ──────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $id         = (int)($_POST['id'] ?? 0);
  $student_id = (int)($_POST['student_id'] ?? 0);
  $month      = trim($_POST['month'] ?? '');

  if (! $student_id) {
    $errors[] = 'Select a student.';
  }
  if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
    $errors[] = 'Month must be in YYYY-MM format.';
  }

  // Collect grades/remarks for each category
  $data = [];
  foreach ($cats as $key => $label) {
    $g = $_POST[$key] ?? '';
    if (! isset($grades[$g])) {
      $errors[] = "Pick a grade for “{$label}.”";
    }
    $tid    = (int)($_POST[$key . '_template'] ?? 0);
    $custom = trim($_POST[$key . '_custom'] ?? '');

    if ($tid && isset($templates[$key])) {
      // ensure template exists
      $found = '';
      foreach ($templates[$key] as $t) {
        if ($t['id'] == $tid) {
          $found = $t['text'];
          break;
        }
      }
      if ($found === '') {
        $errors[] = "Invalid template for “{$label}.”";
      }
      $r = $found;
    } else {
      if ($custom === '') {
        $errors[] = "Enter a remark for “{$label}.”";
      }
      $r = $custom;
    }

    $data[$key]              = $g;
    $data[$remarkCols[$key]] = $r;
  }

  if (empty($errors)) {
    if ($id > 0) {
      // ─── UPDATE
      $sql   = "UPDATE progress SET student_id = ?, month = ?";
      $types = 'is';
      $vals  = [$student_id, $month];
      foreach ($cats as $key => $_) {
        $sql   .= ", {$key} = ?, {$remarkCols[$key]} = ?";
        $types .= 'is';
        $vals[] = $data[$key];
        $vals[] = $data[$remarkCols[$key]];
      }
      $sql   .= " WHERE id = ?";
      $types .= 'i';
      $vals[]  = $id;

      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $stmt->close();

      // ─── FLASH: success message for update
      $_SESSION['flash_success'] = "Progress record #{$id} updated successfully.";
    } else {
      // ─── INSERT
      $cols  = 'student_id,month';
      $ph    = '?,?';
      $types = 'is';
      $vals  = [$student_id, $month];
      foreach ($cats as $key => $_) {
        $cols  .= ", {$key}, {$remarkCols[$key]}";
        $ph    .= ",?,?";
        $types .= 'is';
        $vals[] = $data[$key];
        $vals[] = $data[$remarkCols[$key]];
      }
      $sql  = "INSERT INTO progress ({$cols}) VALUES ({$ph})";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $newId = $stmt->insert_id;
      $stmt->close();

      // ─── FLASH: success message for insert
      $_SESSION['flash_success'] = "New progress record #{$newId} added.";
    }

    header('Location: index.php?page=progress');
    exit;
  }
}

// ─── Fetch all existing progress records ──────────────────────────────
$all = $conn
  ->query("
    SELECT p.*, s.name
      FROM progress p
      JOIN students s ON s.id = p.student_id
     ORDER BY p.month DESC, s.name
  ")
  ->fetch_all(MYSQLI_ASSOC);
?>

<div class="max-w-6xl mx-auto p-6 space-y-6">

  <?php if ($flashSuccess): ?>
    <div class="bg-green-100 text-green-800 p-4 rounded">
      <?= htmlspecialchars($flashSuccess) ?>
    </div>
  <?php endif; ?>
  <?php if ($flashError): ?>
    <div class="bg-red-100 text-red-800 p-4 rounded">
      <?= htmlspecialchars($flashError) ?>
    </div>
  <?php endif; ?>

  <h2 class="text-2xl font-bold">
    <i class="bi bi-bar-chart-line"></i> Manage Progress
  </h2>

  <?php if ($errors): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 p-4 rounded mb-4">
      <ul class="list-disc list-inside">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="grid grid-cols-1 lg:grid-cols-12 gap-4 mb-6">
    <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

    <div class="lg:col-span-3">
      <label class="block text-gray-700 mb-1">Student</label>
      <select name="student_id"
              class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
              required>
        <option value="">— select —</option>
        <?php foreach ($students as $s): ?>
          <option value="<?= $s['id'] ?>"
            <?= ($s['id'] == ($edit['student_id'] ?? '')) ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="lg:col-span-2">
      <label class="block text-gray-700 mb-1">Month</label>
      <input name="month" type="month"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= htmlspecialchars($edit['month'] ?? '') ?>"
             required>
    </div>

    <?php foreach ($cats as $key => $label):
      $gval = $edit[$key] ?? '';
      $rval = $edit[$remarkCols[$key]] ?? '';
    ?>
      <div class="lg:col-span-2">
        <label class="block text-gray-700 mb-1"><?= $label ?> Grade</label>
        <select name="<?= $key ?>"
                class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
                required>
          <option value="">—</option>
          <?php foreach ($grades as $gv => $gt): ?>
            <option value="<?= $gv ?>"
              <?= ($gv == $gval) ? 'selected' : '' ?>>
              <?= $gt ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="lg:col-span-3">
        <label class="block text-gray-700 mb-1"><?= $label ?> Remark</label>
        <div class="flex space-x-2">
          <select name="<?= $key ?>_template"
                  class="w-1/2 border border-gray-300 rounded px-2 py-1 focus:ring focus:border-admin-primary">
            <option value="">— template —</option>
            <?php if (! empty($templates[$key])): ?>
              <?php foreach ($templates[$key] as $t): ?>
                <option value="<?= $t['id'] ?>"
                  <?= ($t['text'] == $rval) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t['text']) ?>
                </option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
          <input
            name="<?= $key ?>_custom"
            class="w-1/2 border border-gray-300 rounded px-2 py-1 focus:ring focus:border-admin-primary"
            placeholder="…or custom…"
            value="<?= htmlspecialchars($rval) ?>">
        </div>
      </div>
    <?php endforeach; ?>

    <div class="lg:col-span-12 text-right">
      <button class="px-6 py-2 bg-admin-primary text-white rounded hover:opacity-90">
        <?= $edit ? 'Save Changes' : 'Add Progress' ?>
      </button>
      <?php if ($edit): ?>
        <a href="index.php?page=progress"
           class="ml-4 px-4 py-2 bg-gray-300 text-gray-800 rounded hover:bg-gray-400">
          Cancel
        </a>
      <?php endif; ?>
    </div>
  </form>

  <h4 class="text-xl font-semibold">Existing Records</h4>
  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">#</th>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Student</th>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Month</th>
          <?php foreach ($cats as $key => $label): ?>
            <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">
              <?= htmlspecialchars($label) ?>
            </th>
          <?php endforeach; ?>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($all)): ?>
          <tr>
            <td colspan="<?= 4 + count($cats) ?>"
                class="px-3 py-6 text-center text-gray-500">
              No records found.
            </td>
          </tr>
        <?php else: foreach ($all as $r): ?>
          <tr>
            <td class="px-3 py-2"><?= $r['id'] ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['name']) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['month']) ?></td>
            <?php foreach ($cats as $key => $label):
              $gv  = $r[$key] ?? '';
              $txt = $grades[$gv] ?? '—';
            ?>
              <td class="px-3 py-2"><?= htmlspecialchars($txt) ?></td>
            <?php endforeach; ?>
            <td class="px-3 py-2 space-x-2">
              <a href="index.php?page=progress&id=<?= $r['id'] ?>"
                 class="px-2 py-1 border border-admin-primary text-admin-primary rounded hover:bg-admin-primary hover:text-white">
                Edit
              </a>
              <a href="index.php?page=progress&delete=<?= $r['id'] ?>"
                 class="px-2 py-1 border border-red-600 text-red-600 rounded hover:bg-red-600 hover:text-white"
                 onclick="return confirm('Delete this?')">
                Delete
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
