<?php
// File: dashboard/admin/progress.php
// Displayed via index.php?page=progress

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─────────────────────────────────────────────────────────────────────────
// Flash messages & CSRF token
// ─────────────────────────────────────────────────────────────────────────
$flash = $_SESSION['flash_success'] ?? $_SESSION['flash_error'] ?? null;
$flashType = isset($_SESSION['flash_success']) ? 'success' : 'error';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$csrf = generate_csrf_token();

// ─────────────────────────────────────────────────────────────────────────
// Define categories, remark columns, grades
// ─────────────────────────────────────────────────────────────────────────
$categories = [
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
$grades = [
  '1' => 'Needs Improvement',
  '2' => 'Average',
  '3' => 'Good',
  '4' => 'Very Good',
  '5' => 'Excellent',
];

// ─────────────────────────────────────────────────────────────────────────
// Handle Add/Edit form submission
// ─────────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF validation
  if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $errors[] = 'Session expired, please refresh and try again.';
  } else {
    $id      = (int)($_POST['id'] ?? 0);
    $student = (int)($_POST['student_id'] ?? 0);
    $month   = trim($_POST['month'] ?? '');

    if (!$student) {
      $errors[] = 'Please select a student.';
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
      $errors[] = 'Please select a valid month.';
    }

    // Validate grades and remarks
    $data = [];
    foreach ($categories as $key => $label) {
      $g = $_POST[$key] ?? '';
      if (!isset($grades[$g])) {
        $errors[] = "Select a grade for {$label}.";
      }
      $r = trim($_POST[$remarkCols[$key]] ?? '');
      if ($r === '') {
        $errors[] = "Enter a remark for {$label}.";
      }
      $data[$key] = $g;
      $data[$remarkCols[$key]] = $r;
    }

    if (!$errors) {
      if ($id) {
        // UPDATE existing record
        $sql = 'UPDATE progress SET student_id=?, month=?';
        $types = 'is';
        $vals = [$student, $month];
        foreach ($categories as $key => $_) {
          $sql .= ", {$key} = ?, {$remarkCols[$key]} = ?";
          $types .= 'is';
          $vals[] = $data[$key];
          $vals[] = $data[$remarkCols[$key]];
        }
        $sql .= ' WHERE id = ?';
        $types .= 'i';
        $vals[] = $id;
      } else {
        // INSERT new record
        $fields = ['student_id', 'month'];
        $placeholders = ['?', '?'];
        $types = 'is';
        $vals = [$student, $month];
        foreach ($categories as $key => $_) {
          $fields[] = $key;
          $fields[] = $remarkCols[$key];
          $placeholders[] = '?';
          $placeholders[] = '?';
          $types .= 'is';
          $vals[] = $data[$key];
          $vals[] = $data[$remarkCols[$key]];
        }
        $sql = 'INSERT INTO progress (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
      }

      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $stmt->close();

      $_SESSION['flash_success'] = $id
        ? "Progress #{$id} updated successfully."
        : 'New progress record added successfully.';

      header('Location: index.php?page=progress');
      exit;
    }
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Load record for add or editing
$edit = [];
if (array_key_exists('edit', $_GET)) {
  $eid = (int)$_GET['edit'];
  if ($eid > 0) {
    $stmt = $conn->prepare('SELECT * FROM progress WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
  }
  // when edit=0 or edit param exists with zero, $edit remains empty array => show blank form
}


// ─────────────────────────────────────────────────────────────────────────
// Filters & Pagination
// ─────────────────────────────────────────────────────────────────────────
$filterStudent = (int)($_GET['student_id'] ?? 0);
$filterMonth   = trim($_GET['month'] ?? '');
if ($filterMonth && !preg_match('/^\d{4}-\d{2}$/', $filterMonth)) {
  $filterMonth = '';
}

$where = [];
$params = [];
$types = '';
if ($filterStudent) {
  $where[] = 'p.student_id = ?';
  $types .= 'i';
  $params[] = $filterStudent;
}
if ($filterMonth) {
  $where[] = "DATE_FORMAT(p.month, '%Y-%m') = ?";
  $types .= 's';
  $params[] = $filterMonth;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 15;
$pageNo  = max(1, (int)($_GET['page_no'] ?? 1));
$offset  = ($pageNo - 1) * $perPage;

// total count
$countSql = "SELECT COUNT(*) FROM progress p $whereSql";
$stmt = $conn->prepare($countSql);
if ($where) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$stmt->bind_result($totalCount);
$stmt->fetch();
$stmt->close();

$totalPages = max(1, (int)ceil($totalCount / $perPage));

// student list for filters
$students = $conn->query('SELECT id, name FROM students ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// build SELECT columns
$cols = 'p.id, p.month, s.name AS student_name';
foreach ($categories as $key => $_) {
  $cols .= ", p.{$key}, p.{$remarkCols[$key]}";
}

$sql = "SELECT {$cols} FROM progress p
        JOIN students s ON s.id = p.student_id
        {$whereSql}
        ORDER BY p.month DESC, s.name
        LIMIT ?, ?";
$stmt = $conn->prepare($sql);
if ($where) {
  $bind = array_merge($params, [$offset, $perPage]);
  $stmt->bind_param($types . 'ii', ...$bind);
} else {
  $stmt->bind_param('ii', $offset, $perPage);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<div class="max-w-7xl mx-auto p-6 space-y-8">
  <!-- Header -->
  <div class="flex justify-between items-center">
    <h1 class="text-3xl font-bold text-gray-800">Progress Manager</h1>
    <a href="?page=progress&edit=0" class="px-4 py-2 bg-indigo-600 text-white rounded shadow hover:bg-indigo-700">+ Add Progress</a>
  </div>

  <!-- Flash -->
  <?php if ($flash): ?>
    <div class="p-4 rounded-md <?= $flashType==='success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
      <?= htmlspecialchars($flash) ?>
    </div>
  <?php endif; ?>

  <!-- Add/Edit Form -->
  <?php if ($edit !== null): ?>
    <div class="bg-white shadow rounded-lg p-6">
      <h2 class="text-2xl font-semibold mb-4"><?= $edit ? 'Edit Progress' : 'Add Progress' ?></h2>
      <?php if ($errors): ?>
        <div class="mb-4 bg-red-50 border-l-4 border-red-400 p-4">
          <ul class="list-disc list-inside text-red-700">
            <?php foreach ($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
      <form method="POST" class="grid grid-cols-1 md:grid-cols-6 gap-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

        <!-- Student & Month -->
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700">Student</label>
          <select name="student_id" required class="mt-1 block w-full border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">Select student</option>
            <?php foreach ($students as $s): ?>
              <option value="<?= $s['id'] ?>" <?= ($edit['student_id'] ?? 0) === $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm font-medium text-gray-700">Month</label>
          <input type="month" name="month" required value="<?= $edit['month'] ?? '' ?>"
                 class="mt-1 block w-full border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
        </div>

        <!-- Grades & Remarks -->
        <?php foreach ($categories as $key => $label): ?>
          <div class="md:col-span-1">
            <label class="block text-sm font-medium text-gray-700"><?= $label ?> Grade</label>
            <select name="<?= $key ?>" required class="mt-1 block w-full border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
              <option value="">—</option>
              <?php foreach($grades as $gv => $gt): ?>
                <option value="<?= $gv ?>" <?= ($edit[$key] ?? '') === $gv ? 'selected' : '' ?>><?= $gt ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-gray-700"><?= $label ?> Remark</label>
            <input type="text" name="<?= $remarkCols[$key] ?>" required
                   value="<?= htmlspecialchars($edit[$remarkCols[$key]] ?? '') ?>"
                   class="mt-1 block w-full border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
          </div>
        <?php endforeach; ?>

        <div class="md:col-span-6 text-right">
          <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md shadow hover:bg-indigo-700">
            <?= $edit ? 'Save Changes' : 'Submit Progress' ?>
          </button>
          <a href="?page=progress" class="ml-4 text-gray-600 hover:underline">Cancel</a>
        </div>
      </form>
    </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="bg-white shadow rounded-lg p-6">
    <h2 class="text-xl font-semibold mb-4">Filters</h2>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <input type="hidden" name="page" value="progress">

      <div>
        <label class="block text-sm font-medium text-gray-700">Student</label>
        <select name="student_id" class="mt-1 block w-full border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
          <option value="">All Students</option>
          <?php foreach ($students as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filterStudent === $s['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700">Month</label>
        <input type="month" name="month" value="<?= $filterMonth ?>"
               class="mt-1 block w-full border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500">
      </div>

      <div class="md:col-span-2 flex space-x-4 items-end">
        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
          Apply
        </button>
        <a href="?page=progress" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
          Reset
        </a>
      </div>
    </form>
  </div>

  <!-- Records Table -->
  <div class="bg-white shadow rounded-lg p-6 overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50 sticky top-0">
        <tr>
          <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Month</th>
          <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Student</th>
          <?php foreach ($categories as $label): ?>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700"><?= $label ?></th>
          <?php endforeach; ?>
          <th class="px-4 py-2 text-center text-sm font-medium text-gray-700">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="<?= count($categories) + 3 ?>" class="px-4 py-6 text-center text-gray-500">
              No records found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr class="hover:bg-gray-50 transition">
              <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($r['month']) ?></td>
              <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($r['student_name']) ?></td>
              <?php foreach (array_keys($categories) as $key): ?>
                <td class="px-4 py-2 text-sm text-gray-700">
                  <?= $grades[$r[$key]] ?? '—' ?><br>
                  <span class="text-xs text-gray-500"><?= htmlspecialchars($r[$remarkCols[$key]]) ?></span>
                </td>
              <?php endforeach; ?>
              <td class="px-4 py-2 text-center space-x-2">
                <a href="?page=progress&edit=<?= $r['id'] ?>" class="px-2 py-1 bg-yellow-400 text-white rounded hover:bg-yellow-500">Edit</a>
                <a href="?page=progress&delete=<?= $r['id'] ?>" class="px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600" onclick="return confirm('Delete record #<?= $r['id'] ?>?');">Delete</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="mt-4 flex justify-center space-x-2">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
          <a href="?page=progress&student_id=<?= $filterStudent ?>&month=<?= $filterMonth ?>&page_no=<?= $p ?>" class="px-3 py-1 rounded-md <?= $p === $pageNo ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700' ?> ">
            <?= $p ?>
          </a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
