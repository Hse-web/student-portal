<?php
// File: dashboard/admin/edit_student.php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── 1) Get & validate ID ────────────────────────────────────────────
$stuId = (int)($_GET['id'] ?? 0);
if ($stuId < 1) {
  $_SESSION['flash_error'] = 'No student specified.';
  header('Location: index.php?page=students');
  exit;
}

// ─── 2) Load “before” snapshot ───────────────────────────────────────
$beforeStmt = $conn->prepare("
  SELECT name,phone,group_name,dob,address,centre_id
    FROM students WHERE id = ?
");
$beforeStmt->bind_param('i', $stuId);
$beforeStmt->execute();
$before = $beforeStmt->get_result()->fetch_assoc();
$beforeStmt->close();

// ─── 3) Load centres, plans & current DB values ─────────────────────
$centres = $conn->query("SELECT id,name FROM centres ORDER BY name")
                 ->fetch_all(MYSQLI_ASSOC);
$plans   = $conn->query("
  SELECT id,centre_id,plan_name,duration_months
    FROM payment_plans
   ORDER BY centre_id,duration_months
")->fetch_all(MYSQLI_ASSOC);

// Get existing student + user info
$stmt = $conn->prepare("
  SELECT u.username,s.name,s.phone,s.group_name,
         s.dob,s.address,s.centre_id
    FROM students s
    JOIN users    u ON u.id = s.user_id
   WHERE s.id = ?
   LIMIT 1
");
$stmt->bind_param('i',$stuId);
$stmt->execute();
$stmt->bind_result(
  $username,$name_db,$phone_db,$group_db,
  $dob_db,$address_db,$centre_db
);
if (! $stmt->fetch()) {
  $_SESSION['flash_error'] = 'Student not found.';
  header('Location: index.php?page=students');
  exit;
}
$stmt->close();

// Sticky & error vars
$name_val    = $_POST['name']       ?? $name_db;
$phone_val   = $_POST['phone']      ?? $phone_db;
$group_val   = $_POST['group_name'] ?? $group_db;
$centre_val  = $_POST['centre_id']  ?? $centre_db;
$plan_val    = $_POST['plan_id']    ?? null;
$dob_val     = $_POST['dob']        ?? $dob_db;
$address_val = $_POST['address']    ?? $address_db;
$errors      = [];
$success     = false;

// ─── 4) Handle POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // a) CSRF
    if (! verify_csrf_token($_POST['csrf_token'] ?? null)) {
      $errors[] = 'Session expired. Please reload and try again.';
    }
    // b) Basic validation
    if (trim($_POST['name']) === '') {
      $errors[] = 'Full Name is required.';
    }

    if (empty($errors)) {
      $conn->begin_transaction();

      // c) Perform UPDATE
      $upd = $conn->prepare("
        UPDATE students
           SET name=?,phone=?,group_name=?,dob=?,address=?,centre_id=?
         WHERE id=?
      ");
      $upd->bind_param(
        'ssssiii',
        $_POST['name'],
        $_POST['phone'],
        $_POST['group_name'],
        $_POST['dob'],
        $_POST['address'],
        $_POST['centre_id'],
        $stuId
      );
      $upd->execute();
      $upd->close();

      // d) Fetch AFTER snapshot
      $afterStmt = $conn->prepare("
        SELECT name,phone,group_name,dob,address,centre_id
          FROM students WHERE id = ?
      ");
      $afterStmt->bind_param('i',$stuId);
      $afterStmt->execute();
      $after = $afterStmt->get_result()->fetch_assoc();
      $afterStmt->close();

      // e) Compute diffs
      $changes = [];
      foreach ($before as $fld => $old) {
        if ((string)$after[$fld] !== (string)$old) {
          $changes[$fld] = ['old'=>$old,'new'=>$after[$fld]];
        }
      }

      // f) Audit log if anything changed
      if (!empty($changes)) {
        log_audit(
          $conn,
          $_SESSION['user_id'],
          'UPDATE',
          'students',
          $stuId,
          $changes
        );
      }

      $conn->commit();
      $success = true;
    }
}
$csrf = generate_csrf_token();
?>
<h2 class="section-header">Edit Student #<?= $stuId ?></h2>

<?php if ($success): ?>
  <div class="alert alert-success">Changes saved successfully.</div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="?page=edit_student&id=<?= $stuId ?>"
      class="card p-4 shadow-sm bg-white">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <div class="row g-3">
    <div class="col-md-6">
      <label class="form-label">Email (username)</label>
      <input type="email" class="form-control" disabled
             value="<?= htmlspecialchars($username) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Full Name</label>
      <input name="name" type="text" class="form-control" required
             value="<?= htmlspecialchars($name_val) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Phone</label>
      <input name="phone" type="text" class="form-control"
             value="<?= htmlspecialchars($phone_val) ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Centre</label>
      <select id="centre_id" name="centre_id" class="form-select" required>
        <?php foreach ($centres as $c): ?>
        <option value="<?= $c['id'] ?>"
          <?= ((int)$centre_val === $c['id']) ? 'selected':'' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Group</label>
      <input name="group_name" type="text" class="form-control" required
             value="<?= htmlspecialchars($group_val) ?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Plan</label>
      <select id="plan_id" name="plan_id" class="form-select" required>
        <option value="">— select plan —</option>
        <?php foreach ($plans as $pl): ?>
        <option
          value="<?= $pl['id'] ?>"
          data-centre-id="<?= $pl['centre_id'] ?>"
          <?= ((int)$plan_val === $pl['id']) ? 'selected':'' ?>>
          <?= htmlspecialchars($pl['plan_name']) ?> —
          <?= $pl['duration_months'] ?>m
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Date of Birth</label>
      <input name="dob" type="date" class="form-control"
             value="<?= htmlspecialchars($dob_val) ?>">
    </div>
    <div class="col-md-9">
      <label class="form-label">Address</label>
      <input name="address" type="text" class="form-control"
             value="<?= htmlspecialchars($address_val) ?>">
    </div>
  </div>
  <div class="mt-4">
    <button type="submit" class="btn btn-success">Save Changes</button>
    <a href="index.php?page=students" class="btn btn-secondary ms-2">Cancel</a>
  </div>
</form>

<script>
  // Same plan‐filter JS as add_student…
  const ctr = document.getElementById('centre_id'),
        pln = document.getElementById('plan_id');
  function filterPlans() {
    const c = ctr.value;
    Array.from(pln.options).forEach(o=>{
      o.hidden = o.value && (o.dataset.centreId !== c);
    });
    if (pln.selectedOptions[0]?.hidden) pln.value = '';
  }
  ctr.addEventListener('change', filterPlans);
  document.addEventListener('DOMContentLoaded', filterPlans);
</script>
