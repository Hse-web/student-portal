<?php
// File: dashboard/admin/assign_homework.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';

// 1) Auth guard
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../../login/index.php');
  exit;
}

// 2) Load centre → groups
$centreGroups = [];
$res = $conn->query("
  SELECT DISTINCT p.centre_id, p.group_name
    FROM payment_plans p
   ORDER BY p.centre_id, p.group_name
");
while ($r = $res->fetch_assoc()) {
  $centreGroups[$r['centre_id']][] = $r['group_name'];
}
$res->free();

// 3) Load centres
$centres = $conn->query("SELECT id, name FROM centres ORDER BY name")
                ->fetch_all(MYSQLI_ASSOC);

$errors  = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // pull cleanly with null‐coalescing
  $centre_id   = (int)($_POST['centre_id']  ?? 0);
  $group_name  = trim($_POST['group_name']  ?? '');
  $title       = trim($_POST['title']       ?? '');
  $description = trim($_POST['description'] ?? '');

  // handle file upload
  $file_path = null;
  if (!empty($_FILES['attachment']['name'])) {
    $f     = $_FILES['attachment'];
    $types = ['application/pdf','image/jpeg','image/png','image/jpg'];
    if ($f['error']===0 && in_array($f['type'],$types)) {
      $dir = __DIR__ . '/../../uploads/homework_attachments/';
      if (!is_dir($dir)) mkdir($dir,0755,true);
      $fn = uniqid().'_'.basename($f['name']);
      if (move_uploaded_file($f['tmp_name'], "$dir$fn")) {
        $file_path = "uploads/homework_attachments/$fn";
      } else {
        $errors[] = 'Attachment upload failed.';
      }
    } else {
      $errors[] = 'Invalid attachment type.';
    }
  }

  // validation
  if (!$centre_id)      $errors[] = 'Pick a centre.';
  if ($group_name==='') $errors[] = 'Pick a group.';
  if ($title==='')      $errors[] = 'Enter a title.';
  if ($description==='')$errors[] = 'Enter a description.';

  if (empty($errors)) {
    // find matching students
    $stmt = $conn->prepare("
      SELECT id FROM students
       WHERE centre_id = ? AND group_name = ?
    ");
    $stmt->bind_param('is',$centre_id,$group_name);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id');
    $stmt->close();

    if (empty($ids)) {
      $errors[] = 'No students found in that group.';
    } else {
      // insert one per student
      $ins = $conn->prepare("
        INSERT INTO homework_assigned
          (student_id,date_assigned,title,description,file_path)
        VALUES (?, NOW(), ?, ?, ?)
      ");
      foreach ($ids as $sid) {
        $ins->bind_param('isss',$sid,$title,$description,$file_path);
        $ins->execute();
      }
      $ins->close();
      $success = 'Assigned to '.count($ids).' student(s).';
    }
  }
}

// ----------------------------------------------------------------
// Now fetch your “Existing Assignments” summary
// ----------------------------------------------------------------
$assignments = [];
$res = $conn->query("
  SELECT
    DATE_FORMAT(ha.date_assigned,'%d %b %Y') AS date,
    c.name   AS centre,
    s.group_name     AS `group`,
    ha.title,
    COUNT(*)        AS student_count,
    ha.file_path    AS attachment,
    MIN(ha.id)      AS example_id
  FROM homework_assigned ha
  JOIN students        s ON s.id = ha.student_id
  JOIN centres         c ON c.id = s.centre_id
  GROUP BY ha.date_assigned, c.name, s.group_name, ha.title, ha.file_path
  ORDER BY ha.date_assigned DESC
");
while ($r = $res->fetch_assoc()) {
  $assignments[] = $r;
}
$res->free();
?>
<div class="container py-4">
  <h2>📘 Assign Homework</h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><?= implode('<br>',$errors) ?></div>
  <?php elseif ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label">Centre</label>
        <select id="centre" name="centre_id"
                class="form-select" required>
          <option value="">— Select centre —</option>
          <?php foreach ($centres as $c): ?>
            <option value="<?= $c['id'] ?>"
              <?= ((int)($_POST['centre_id'] ?? 0) === $c['id'])
                  ? 'selected':'' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Group</label>
        <select id="group" name="group_name"
                class="form-select" required>
          <option value="">— Select group —</option>
        </select>
      </div>
    </div>

    <div class="mt-4 mb-3">
      <label class="form-label">Homework Title</label>
      <input type="text" name="title"
             class="form-control"
             value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
             required>
    </div>

    <div class="mb-3">
      <label class="form-label">Description</label>
      <textarea name="description" rows="4"
                class="form-control" required><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
    </div>

    <div class="mb-3">
      <label class="form-label">Attachment (PDF/JPG/PNG)</label>
      <input type="file" name="attachment"
             accept=".pdf,.jpeg,.jpg,.png"
             class="form-control">
    </div>

    <button class="btn btn-primary">Assign Homework</button>
  </form>


  <hr class="my-5">

  <h3>Existing Assignments</h3>
  <div class="table-responsive">
    <table class="table table-striped align-middle">
      <thead class="table-light">
        <tr>
          <th>Date</th>
          <th>Centre</th>
          <th>Group</th>
          <th>Title</th>
          <th># Students</th>
          <th>Attachment</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($assignments)): ?>
          <tr><td colspan="7" class="text-center">No assignments yet.</td></tr>
        <?php else: ?>
          <?php foreach ($assignments as $a): ?>
            <tr>
              <td><?= htmlspecialchars($a['date']) ?></td>
              <td><?= htmlspecialchars($a['centre']) ?></td>
              <td><?= htmlspecialchars($a['group']) ?></td>
              <td><?= htmlspecialchars($a['title']) ?></td>
              <td><?= (int)$a['student_count'] ?></td>
              <td>
                <?php if ($a['attachment']): ?>
                  <a href="/<?= htmlspecialchars($a['attachment']) ?>"
                     class="btn btn-sm btn-outline-secondary"
                     target="_blank">
                    Download
                  </a>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>
              <td>
                <a href="?delete=<?= (int)$a['example_id'] ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Delete this assignment?')">
                  <i class="bi bi-trash"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  // client-side: when centre changes, refresh the groups
  const centreGroups = <?= json_encode($centreGroups, JSON_HEX_TAG) ?>;
  const centreEl     = document.getElementById('centre');
  const groupEl      = document.getElementById('group');
  function refreshGroups() {
    const c = centreEl.value;
    groupEl.innerHTML = '<option value="">— Select group —</option>';
    (centreGroups[c]||[]).forEach(g=>{
      const o = document.createElement('option');
      o.value = g; o.textContent = g;
      if (g === <?= json_encode($_POST['group_name'] ?? '', JSON_HEX_TAG) ?>) {
        o.selected = true;
      }
      groupEl.appendChild(o);
    });
  }
  centreEl.addEventListener('change', refreshGroups);
  window.addEventListener('DOMContentLoaded', refreshGroups);
</script>
