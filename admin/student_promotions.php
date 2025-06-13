<?php
// File: dashboard/admin/student_promotions.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── Handle POST (Save / Delete) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('Session expired. Please reload.', 'danger');
    header('Location:?page=student_promotions');
    exit;
  }

  $action = $_POST['action'] ?? '';

  // Save (insert or update)
  if ($action === 'save') {
    $id            = (int)($_POST['id'] ?? 0);
    $student_id    = (int)$_POST['student_id'];
    $art_group_id  = (int)$_POST['art_group_id'];
    $effective_date= $_POST['effective_date'];
    $is_applied    = isset($_POST['is_applied']) ? 1 : 0;

    if ($id) {
      $stmt = $conn->prepare("
        UPDATE student_promotions
           SET student_id=?, art_group_id=?, effective_date=?, is_applied=?
         WHERE id=?
      ");
      $stmt->bind_param('iisii', $student_id, $art_group_id, $effective_date, $is_applied, $id);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO student_promotions
          (student_id, art_group_id, effective_date, is_applied)
        VALUES (?, ?, ?, ?)
      ");
      $stmt->bind_param('iisi', $student_id, $art_group_id, $effective_date, $is_applied);
    }
    $stmt->execute();
    $stmt->close();

    set_flash('Promotion saved.', 'success');
    header("Location:?page=student_promotions&student_id={$student_id}");
    exit;
  }

  // Delete
  if ($action === 'delete' && !empty($_POST['id'])) {
    $id = (int)$_POST['id'];
    // capture student_id for redirect
    $sid = $conn
      ->query("SELECT student_id FROM student_promotions WHERE id={$id}")
      ->fetch_assoc()['student_id'];

    $stmt = $conn->prepare("DELETE FROM student_promotions WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();

    set_flash('Promotion deleted.', 'success');
    header("Location:?page=student_promotions&student_id={$sid}");
    exit;
  }
}

// ─── Fetch students & art groups ───────────────────────────────────
$students = $conn
  ->query("SELECT id, name FROM students ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$groups = $conn
  ->query("SELECT id, label FROM art_groups ORDER BY sort_order")
  ->fetch_all(MYSQLI_ASSOC);

// ─── Determine filter student_id ───────────────────────────────────
$filterSid = (int)($_GET['student_id'] ?? $students[0]['id'] ?? 0);

// ─── Fetch that student’s promotions ───────────────────────────────
$stmt = $conn->prepare("
  SELECT sp.id, sp.effective_date, sp.is_applied,
         ag.label AS group_label
    FROM student_promotions sp
    JOIN art_groups ag ON ag.id=sp.art_group_id
   WHERE sp.student_id=?
   ORDER BY sp.effective_date DESC, sp.is_applied DESC
");
$stmt->bind_param('i', $filterSid);
$stmt->execute();
$promos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── CSRF token & flash ────────────────────────────────────────────
$csrf  = generate_csrf_token();
$flash = get_flash();
?>
<div class="bg-white p-6 rounded-lg shadow">
  <h2 class="text-2xl font-semibold mb-4">Manage Student Promotions</h2>

  <?php if ($flash): ?>
    <div class="mb-4 p-3 bg-<?= $flash['type']==='danger'?'red':'green' ?>-100 
                border border-<?= $flash['type']==='danger'?'red':'green' ?>-400 
                text-<?= $flash['type']==='danger'?'red':'green' ?>-700 rounded">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Student Filter -->
  <form method="get" class="mb-4">
    <input type="hidden" name="page" value="student_promotions">
    <select name="student_id" onchange="this.form.submit()"
            class="border p-2 rounded">
      <?php foreach ($students as $s): ?>
        <option value="<?= $s['id'] ?>"
          <?= $s['id']==$filterSid?'selected':'' ?>>
          <?= htmlspecialchars($s['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>

  <!-- Add / Edit Form -->
  <form method="post" class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
    <input type="hidden" name="action"        value="save">
    <input type="hidden" name="id"            value="0">

    <select name="student_id" class="border p-2 rounded" required>
      <?php foreach ($students as $s): ?>
        <option value="<?= $s['id'] ?>"
          <?= $s['id']==$filterSid?'selected':'' ?>>
          <?= htmlspecialchars($s['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="art_group_id" class="border p-2 rounded" required>
      <?php foreach ($groups as $g): ?>
        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['label']) ?></option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="effective_date" class="border p-2 rounded" 
      value="<?= date('Y-m-d') ?>" required>

    <label class="inline-flex items-center">
      <input type="checkbox" name="is_applied" class="form-checkbox">
      <span class="ml-2">Apply now</span>
    </label>

    <button type="submit"
            class="bg-blue-600 text-white px-4 py-2 rounded hover:opacity-90">
      Save Promotion
    </button>
  </form>

  <!-- Existing Promotions -->
  <table class="w-full table-auto text-left">
    <thead class="bg-gray-100">
      <tr>
        <th class="px-4 py-2">Group</th>
        <th class="px-4 py-2">Effective Date</th>
        <th class="px-4 py-2">Applied?</th>
        <th class="px-4 py-2">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($promos)): ?>
        <tr><td colspan="4" class="p-4 text-center text-gray-500">
          No promotions yet for this student.
        </td></tr>
      <?php else: foreach ($promos as $p): ?>
        <tr class="border-t">
          <td class="px-4 py-2"><?= htmlspecialchars($p['group_label']) ?></td>
          <td class="px-4 py-2"><?= htmlspecialchars($p['effective_date']) ?></td>
          <td class="px-4 py-2"><?= $p['is_applied']?'Yes':'No' ?></td>
          <td class="px-4 py-2 space-x-2">
            <button class="edit-btn px-3 py-1 bg-green-600 text-white rounded hover:opacity-90"
                    data-id="<?= $p['id'] ?>"
                    data-date="<?= $p['effective_date'] ?>"
                    data-applied="<?= $p['is_applied'] ?>"
                    data-group="<?= htmlspecialchars($p['group_label'],ENT_QUOTES) ?>">
              Edit
            </button>
            <form method="post" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action"        value="delete">
              <input type="hidden" name="id"            value="<?= $p['id'] ?>">
              <button type="submit"
                      class="px-3 py-1 bg-red-600 text-white rounded hover:opacity-90"
                      onclick="return confirm('Delete this promotion?')">
                Delete
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
  // Edit button fills the form above
  document.querySelectorAll('.edit-btn').forEach(btn=>{
    btn.addEventListener('click',()=>{
      const form = btn.closest('main').querySelector('form[method=post]');
      form.id.value             = btn.dataset.id;
      form.effective_date.value = btn.dataset.date;
      form.is_applied.checked   = btn.dataset.applied==='1';
      // set group select to the matching label
      [...form.art_group_id.options].find(opt=>
        opt.textContent === btn.dataset.group
      ).selected = true;
      form.scrollIntoView({behavior:'smooth'});
    });
  });
</script>
