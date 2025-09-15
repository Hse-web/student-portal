<?php
// File: dashboard/admin/student_promotions.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─────────────────────────────────────────────────────────────────────────────
// 1) Handle POST: Save or Delete a promotion
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('Session expired; please reload.', 'danger');
    $sid = (int)($_POST['student_id'] ?? 0);
    header("Location:?page=student_promotions&student_id={$sid}");
    exit;
  }

  $action     = $_POST['action'] ?? '';
  $student_id = (int)($_POST['student_id'] ?? 0);

  // 1.a) Create or update
  if ($action === 'save') {
    $id             = (int)($_POST['id'] ?? 0);
    $art_group_id   = (int)($_POST['art_group_id'] ?? 0);
    $effective_date = $_POST['effective_date'] ?? '';
    $is_applied     = isset($_POST['is_applied']) ? 1 : 0;

    // Only check for duplicates when inserting (not editing)
    if ($id == 0) {
      $check = $conn->prepare("SELECT id FROM student_promotions WHERE student_id=? AND art_group_id=? AND effective_date=? LIMIT 1");
      $check->bind_param('iis', $student_id, $art_group_id, $effective_date);
      $check->execute();
      $check->store_result();
      if ($check->num_rows > 0) {
        $check->close();
        set_flash('This promotion already exists for this student and date.', 'danger');
        header("Location:?page=student_promotions&student_id={$student_id}");
        exit;
      }
      $check->close();
    }

    // Now proceed with update or insert
    if ($id > 0) {
      $stmt = $conn->prepare("
        UPDATE student_promotions
           SET art_group_id   = ?,
               effective_date = ?,
               is_applied     = ?
         WHERE id = ?
      ");
      $stmt->bind_param('isii', $art_group_id, $effective_date, $is_applied, $id);
    } else {
      $stmt = $conn->prepare("
        INSERT INTO student_promotions
          (student_id, art_group_id, effective_date, is_applied)
        VALUES (?, ?, ?, ?)
      ");
      $stmt->bind_param('iisi', $student_id, $art_group_id, $effective_date, $is_applied);
    }

    // Catch duplicate by SQL error (in case of race condition)
    try {
      $stmt->execute();
    } catch (mysqli_sql_exception $e) {
      if ($e->getCode() == 1062) { // Duplicate entry
        set_flash('Duplicate promotion detected.', 'danger');
      } else {
        set_flash('Database error: ' . $e->getMessage(), 'danger');
      }
      header("Location:?page=student_promotions&student_id={$student_id}");
      exit;
    }
    $stmt->close();

    // If they checked “apply now” send notification
    if ($is_applied) {
      $lbl = $conn->prepare("SELECT label FROM art_groups WHERE id=? LIMIT 1");
      $lbl->bind_param('i', $art_group_id);
      $lbl->execute();
      $lbl->bind_result($group_label);
      $lbl->fetch();
      $lbl->close();

      create_notification(
        $conn,
        [$student_id],
        'Group Promotion',
        "You have been promoted to “{$group_label}” effective {$effective_date}."
      );
    }

    set_flash('Promotion saved.', 'success');
    header("Location:?page=student_promotions&student_id={$student_id}");
    exit;
  }

  // 1.b) Delete
  if ($action === 'delete') {
    $promo_id = (int)($_POST['id'] ?? 0);

    // Look up that promo’s student_id so we can redirect back
    $tmp = $conn->prepare("SELECT student_id FROM student_promotions WHERE id=? LIMIT 1");
    $tmp->bind_param('i', $promo_id);
    $tmp->execute();
    $tmp->bind_result($student_id);
    $tmp->fetch();
    $tmp->close();

    $del = $conn->prepare("DELETE FROM student_promotions WHERE id=?");
    $del->bind_param('i', $promo_id);
    $del->execute();
    $del->close();

    set_flash('Promotion deleted.', 'success');
    header("Location:?page=student_promotions&student_id={$student_id}");
    exit;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) Load all students and groups
// ─────────────────────────────────────────────────────────────────────────────
$students = $conn
  ->query("SELECT id,name FROM students ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$groups = $conn
  ->query("SELECT id,label FROM art_groups ORDER BY sort_order")
  ->fetch_all(MYSQLI_ASSOC);

// ─────────────────────────────────────────────────────────────────────────────
// 3) Determine which student we’re viewing
// ─────────────────────────────────────────────────────────────────────────────
// Only ever read “student_id” once here; if absent, default to the first student.
$filterSid = (int)($_GET['student_id'] ?? $students[0]['id'] ?? 0);

// ─────────────────────────────────────────────────────────────────────────────
// 4) Fetch this student’s existing promotions
// ─────────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
  SELECT 
    sp.id,
    sp.art_group_id,
    sp.effective_date,
    sp.is_applied,
    ag.label AS group_label
  FROM student_promotions sp
  JOIN art_groups       ag ON ag.id = sp.art_group_id
  WHERE sp.student_id = ?
  ORDER BY sp.effective_date DESC, sp.is_applied DESC
");
$stmt->bind_param('i', $filterSid);
$stmt->execute();
$promos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─────────────────────────────────────────────────────────────────────────────
// 5) What is this student’s current group? (for the default in the form)
// ─────────────────────────────────────────────────────────────────────────────
$currentGroupId = get_current_group_id($conn, $filterSid);

// ─────────────────────────────────────────────────────────────────────────────
// 6) CSRF & flash
// ─────────────────────────────────────────────────────────────────────────────
$csrf  = generate_csrf_token();
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Admin – Student Promotions</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
  />
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2>Manage Student Promotions</h2>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'danger' ? 'danger' : 'success' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

   <!-- ───── Student selector ─────────────────────────────────────────────── -->
<form method="get" class="mb-4">
  <input type="hidden" name="page" value="student_promotions">
  <select
    name="student_id"
    class="form-select form-select-sm w-auto"
    onchange="this.form.submit()">
    <?php foreach ($students as $s): ?>
      <option
        value="<?= $s['id'] ?>"
        <?= (int)$s['id'] === $filterSid ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>
</form>


    <!-- ───── Add / Edit Promotion Form ────────────────────────────────────── -->
    <form id="promoForm" method="post" class="row gx-2 gy-2 align-items-end mb-4">
      <input type="hidden" name="csrf_token"   value="<?= $csrf ?>">
      <input type="hidden" name="action"       value="save">
      <input type="hidden" name="id"           value="0">
      <input type="hidden" name="student_id"   value="<?= $filterSid ?>">

      <div class="col-auto">
        <select name="art_group_id" class="form-select form-select-sm" required>
          <?php foreach ($groups as $g): ?>
            <option
              value="<?= $g['id'] ?>"
              <?= (int)$g['id'] === $currentGroupId ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($g['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-auto">
        <input
          type="date"
          name="effective_date"
          class="form-control form-control-sm"
          value="<?= date('Y-m-d') ?>"
          required
        >
      </div>

      <div class="col-auto form-check">
        <input class="form-check-input" type="checkbox" name="is_applied" id="applyNow">
        <label class="form-check-label" for="applyNow">Apply now</label>
      </div>

      <div class="col-auto">
        <button class="btn btn-sm btn-primary">Save Promotion</button>
      </div>
    </form>

    <!-- ───── Existing Promotions ───────────────────────────────────────────── -->
    <div class="table-responsive">
      <table class="table table-striped">
        <thead class="table-light">
          <tr>
            <th>Group</th>
            <th>Date</th>
            <th>Applied?</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($promos)): ?>
            <tr>
              <td colspan="4" class="text-center text-muted py-4">
                No promotions for this student.
              </td>
            </tr>
          <?php else: foreach ($promos as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['group_label']) ?></td>
              <td><?= htmlspecialchars($p['effective_date']) ?></td>
              <td><?= $p['is_applied'] ? 'Yes' : 'No' ?></td>
              <td>
                <button
                  type="button"
                  class="btn btn-sm btn-success edit-btn"
                  data-id="<?= $p['id'] ?>"
                  data-group-id="<?= $p['art_group_id'] ?>"
                  data-date="<?= $p['effective_date'] ?>"
                  data-applied="<?= (int)$p['is_applied'] ?>"
                >
                  Edit
                </button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="action"     value="delete">
                  <input type="hidden" name="student_id" value="<?= $filterSid ?>">
                  <input type="hidden" name="id"         value="<?= $p['id'] ?>">
                  <button class="btn btn-sm btn-danger"
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
  </div>

  <script>
    // Prefill the “edit” form
    document.querySelectorAll('.edit-btn').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const form = document.getElementById('promoForm');
        form.id.value           = btn.dataset.id;
        form.art_group_id.value = btn.dataset.groupId;
        form.effective_date.value = btn.dataset.date;
        form.is_applied.checked   = btn.dataset.applied === '1';
        form.scrollIntoView({behavior:'smooth'});
      });
    });
  </script>
  <script>
    // Prevent double submissions
    document.addEventListener('DOMContentLoaded', function() {
      const form = document.getElementById('promoForm');
      if (form) {
        let isSubmitting = false;
        form.addEventListener('submit', function(e) {
          if (isSubmitting) {
            e.preventDefault();
            return;
          }
          isSubmitting = true;
          const submitBtn = form.querySelector('button[type="submit"],button.btn-primary');
          if(submitBtn) submitBtn.disabled = true;
        });
      }
    });
  </script>
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
  ></script>
</body>
</html>
