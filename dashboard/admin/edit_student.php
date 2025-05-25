<?php
// dashboard/admin/edit_student.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1) Figure out which student we’re editing
//    If no valid ?id=, bounce back to list
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId < 1) {
    header('Location: index.php?page=students');
    exit;
}

// 2) Fetch Centres & Plans for the dropdowns
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$plans = $conn
  ->query("
    SELECT id, centre_id, plan_name, duration_months, amount
      FROM payment_plans
     ORDER BY centre_id, duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

// 3) Load the existing student record + linked user_id
$stmt = $conn->prepare("
  SELECT 
    u.id      AS user_id,
    u.username AS email,
    s.name,
    s.phone,
    s.group_name,
    s.dob,
    s.address,
    s.centre_id,
    s.is_legacy
  FROM students s
  JOIN users    u ON u.id = s.user_id
  WHERE s.id = ?
  LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result(
  $userId,
  $existingEmail,
  $existingName,
  $existingPhone,
  $existingGroup,
  $existingDob,
  $existingAddress,
  $existingCentre,
  $existingLegacy
);
if (! $stmt->fetch()) {
    // no such student
    $stmt->close();
    header('Location: index.php?page=students');
    exit;
}
$stmt->close();

// 4) Find their current plan (latest subscription)
$stmt = $conn->prepare("
  SELECT plan_id
    FROM student_subscriptions
   WHERE student_id = ?
   ORDER BY subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($existingPlan);
$stmt->fetch();
$stmt->close();

// 5) Handle the POST (update)
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (! verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Session expired—please reload the form.';
  }

  // Collect & sanitize
  $email      = trim($_POST['email']     ?? '');
  $name       = trim($_POST['name']      ?? '');
  $phone      = trim($_POST['phone']     ?? '');
  $groupName  = trim($_POST['group_name']?? '');
  $dob        = $_POST['dob']            ?? null;
  $address    = trim($_POST['address']   ?? '');
  $centreId   = (int)($_POST['centre_id']?? 0);
  $planId     = (int)($_POST['plan_id']  ?? 0);
  $isLegacy   = !empty($_POST['is_legacy']) ? 1 : 0;

  // Validation
  if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
  }
  if ($name === '') {
    $errors[] = 'Full name is required.';
  }
  if ($centreId < 1) {
    $errors[] = 'Please select a centre.';
  }
  if ($groupName === '') {
    $errors[] = 'Please enter a group name.';
  }
  if ($planId < 1) {
    $errors[] = 'Please select a plan.';
  }

  if (empty($errors)) {
    $conn->begin_transaction();
    try {
      //
      // a) Update users table (email & centre)
      //
      $u = $conn->prepare("
        UPDATE users
           SET username = ?, centre_id = ?
         WHERE id = ?
      ");
      $u->bind_param('sii', $email, $centreId, $userId);
      $u->execute();
      $u->close();

      //
      // b) Update students table
      //
      $s = $conn->prepare("
        UPDATE students
           SET
             name        = ?,
             email       = ?,
             phone       = ?,
             group_name  = ?,
             dob         = ?,
             address     = ?,
             centre_id   = ?,
             is_legacy   = ?
         WHERE id = ?
      ");
      $s->bind_param(
        'ssssssiii',
        $name,
        $email,
        $phone,
        $groupName,
        $dob,
        $address,
        $centreId,
        $isLegacy,
        $studentId
      );
      $s->execute();
      $s->close();

      //
      // c) If the plan changed, insert a new subscription
      //
      if ($planId !== $existingPlan) {
        $now = date('Y-m-d H:i:s');
        $ins = $conn->prepare("
          INSERT INTO student_subscriptions
            (student_id,plan_id,subscribed_at)
          VALUES (?,?,?)
        ");
        $ins->bind_param('iis', $studentId, $planId, $now);
        $ins->execute();
        $ins->close();

        //
        // d) (optional) recompute payments exactly as in add_student…
        //    …you can copy-paste your fee logic here if needed.
        //
      }

      $conn->commit();
      $_SESSION['flash_success'] = 'Student updated successfully.';
      header('Location: index.php?page=students');
      exit;
    }
    catch (Throwable $e) {
      $conn->rollback();
      $errors[] = 'Error updating student: ' . $e->getMessage();
    }
  }
}

// 6) Generate a fresh CSRF for the form
$csrf = generate_csrf_token();

// 7) Pre-fill form values (POST wins over existing)
$form = [
  'email'      => htmlspecialchars($_POST['email']       ?? $existingEmail),
  'name'       => htmlspecialchars($_POST['name']        ?? $existingName),
  'phone'      => htmlspecialchars($_POST['phone']       ?? $existingPhone),
  'group_name' => htmlspecialchars($_POST['group_name']  ?? $existingGroup),
  'dob'        => htmlspecialchars($_POST['dob']         ?? $existingDob),
  'address'    => htmlspecialchars($_POST['address']     ?? $existingAddress),
  'centre_id'  => (int)      ($_POST['centre_id']  ?? $existingCentre),
  'plan_id'    => (int)      ($_POST['plan_id']    ?? $existingPlan),
  'is_legacy'  => !empty($_POST['is_legacy']) ? 1 : $existingLegacy,
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Student #<?= $studentId ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:800px">
  <h2>Edit Student</h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach ?>
      </ul>
    </div>
  <?php endif ?>

  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

    <div class="col-md-6">
      <label>Email</label>
      <input name="email" type="email" required class="form-control"
             value="<?= $form['email'] ?>">
    </div>
    <div class="col-md-6">
      <label>Full Name</label>
      <input name="name" type="text" required class="form-control"
             value="<?= $form['name'] ?>">
    </div>
    <div class="col-md-6">
      <label>Phone</label>
      <input name="phone" type="text" class="form-control"
             value="<?= $form['phone'] ?>">
    </div>
    <div class="col-md-6">
      <label>Group</label>
      <input name="group_name" type="text" required class="form-control"
             value="<?= $form['group_name'] ?>">
    </div>
    <div class="col-md-4">
      <label>Centre</label>
      <select name="centre_id" class="form-select" required>
        <option value="">— Select Centre —</option>
        <?php foreach($centres as $c): ?>
        <option
          value="<?= $c['id'] ?>"
          <?= $form['centre_id'] === $c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label>Plan</label>
      <select name="plan_id" class="form-select" required>
        <option value="">— Select Plan —</option>
        <?php foreach($plans as $p): ?>
        <option
          value="<?= $p['id'] ?>"
          data-centre-id="<?= $p['centre_id'] ?>"
          <?= $form['plan_id'] === $p['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['plan_name']) ?>
          — <?= $p['duration_months'] ?>m / ₹<?= number_format($p['amount'],2) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4 form-check">
      <input
        class="form-check-input"
        type="checkbox"
        id="is_legacy"
        name="is_legacy"
        value="1"
        <?= $form['is_legacy'] ? 'checked':'' ?>>
      <label class="form-check-label" for="is_legacy">
        Skip one-time fees (already paid)
      </label>
    </div>
    <div class="col-md-6">
      <label>Date of Birth</label>
      <input name="dob" type="date" class="form-control"
             value="<?= $form['dob'] ?>">
    </div>
    <div class="col-md-6">
      <label>Address</label>
      <input name="address" type="text" class="form-control"
             value="<?= $form['address'] ?>">
    </div>

    <div class="col-12 text-end">
      <button class="btn btn-success">Save Changes</button>
      <a href="index.php?page=students" class="btn btn-secondary ms-2">
        Cancel
      </a>
    </div>
  </form>
</div><!-- /.container -->

<script>
  // (optional) if you want dynamic centre→plan filtering like in add_student
  document.querySelector('select[name=centre_id]').addEventListener('change', e=>{
    const cid = e.target.value;
    document.querySelectorAll('select[name=plan_id] option').forEach(opt=>{
      opt.style.display = (opt.getAttribute('data-centre-id')===cid) ? '' : 'none';
    });
  });
</script>

</body>
</html>
