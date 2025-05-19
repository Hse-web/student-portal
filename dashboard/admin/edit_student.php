<?php
// File: dashboard/admin/edit_student.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
// ─── 1) Auth guard ─────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../login/index.php');
    exit;
}

// ─── 2) Get & validate student ID ─────────────────────────────────────
$stuId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($stuId < 1) {
    echo '<div class="alert alert-danger m-3">No student specified.</div>';
    exit;
}

// ─── 3) Load centres & plans for the dropdown ─────────────────────────
$centres = $conn
  ->query("SELECT id, name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$plans = $conn
  ->query("SELECT id, centre_id, plan_name, duration_months
            FROM payment_plans
           ORDER BY centre_id, duration_months")
  ->fetch_all(MYSQLI_ASSOC);

// ─── 4) Load existing student + user info ──────────────────────────────
$stmt = $conn->prepare("
  SELECT u.username, s.name, s.phone, s.group_name,
         s.dob, s.address, s.centre_id
    FROM students AS s
    JOIN users    AS u ON u.id = s.user_id
   WHERE s.id = ?
   LIMIT 1
");
$stmt->bind_param('i', $stuId);
$stmt->execute();
$stmt->bind_result(
    $username,
    $name_db,
    $phone_db,
    $group_db,
    $dob_db,
    $address_db,
    $centre_db
);
if (! $stmt->fetch()) {
    echo '<div class="alert alert-danger m-3">Student not found.</div>';
    exit;
}
$stmt->close();

// ─── 5) Fetch their current plan_id ───────────────────────────────────
$stmt = $conn->prepare("
  SELECT plan_id
    FROM student_subscriptions
   WHERE student_id = ?
   ORDER BY subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $stuId);
$stmt->execute();
$stmt->bind_result($currentPlanId);
$stmt->fetch();
$stmt->close();

// ─── 6) Sticky form values & error/success flags ───────────────────────
$name_val    = $_POST['name']        ?? $name_db;
$phone_val   = $_POST['phone']       ?? $phone_db;
$group_val   = $_POST['group_name']  ?? $group_db;
$centre_val  = $_POST['centre_id']   ?? $centre_db;
$plan_val    = $_POST['plan_id']     ?? $currentPlanId;
$dob_val     = $_POST['dob']         ?? $dob_db;
$address_val = $_POST['address']     ?? $address_db;

$errors = [];
$success = false;

// ─── 7) Handle form POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['name']) === '') {
        $errors[] = 'Full Name is required.';
    }
    if (empty($errors)) {
        // a) Update the student record
        $upd = $conn->prepare("
          UPDATE students
             SET name=?, phone=?, group_name=?, dob=?, address=?, centre_id=?
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

        // b) If plan changed → insert new subscription + pending payment
        if ((int)$_POST['plan_id'] !== (int)$currentPlanId) {
            // — insert subscription
            $now = date('Y-m-d H:i:s');
            $ins = $conn->prepare("
              INSERT INTO student_subscriptions
                (student_id, plan_id, subscribed_at)
              VALUES (?,?,?)
            ");
            $ins->bind_param('iis', $stuId, $_POST['plan_id'], $now);
            $ins->execute();
            $ins->close();

            // — compute $due (you can copy your existing logic here)
            // — insert into payments with status='Pending', amount_due=$due, amount_paid=0
            //   (omitted for brevity)
        }

        // show success banner
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Student #<?= htmlspecialchars($stuId) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <style>
    .section-header {
      margin: 1.5rem 0 1rem;
      color: #d84315;
      font-weight: 600;
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-4" style="max-width:800px;">

    <h2 class="section-header">Edit Student #<?= $stuId ?></h2>

    <!-- success -->
    <?php if ($success): ?>
      <div class="alert alert-success">Changes updated successfully!</div>
    <?php endif; ?>

    <!-- errors -->
    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action=""
          class="card p-4 shadow-sm bg-white">
      <div class="row g-3">

        <!-- Email (readonly) -->
        <div class="col-md-6">
          <label class="form-label">Email (username)</label>
          <input type="email" class="form-control" disabled
                 value="<?= htmlspecialchars($username) ?>">
        </div>

        <!-- Full Name -->
        <div class="col-md-6">
          <label class="form-label">Full Name</label>
          <input name="name" type="text" class="form-control" required
                 value="<?= htmlspecialchars($name_val) ?>">
        </div>

        <!-- Phone -->
        <div class="col-md-4">
          <label class="form-label">Phone</label>
          <input name="phone" type="text" class="form-control"
                 value="<?= htmlspecialchars($phone_val) ?>">
        </div>

        <!-- Centre -->
        <div class="col-md-4">
          <label class="form-label">Centre</label>
          <select name="centre_id" id="centre_id"
                  class="form-select" required>
            <?php foreach ($centres as $c): ?>
              <option value="<?= $c['id'] ?>"
                <?= ((int)$centre_val === (int)$c['id']) ? 'selected':'' ?>>
                <?= htmlspecialchars($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Group -->
        <div class="col-md-4">
          <label class="form-label">Group</label>
          <input name="group_name" type="text"
                 class="form-control" required
                 value="<?= htmlspecialchars($group_val) ?>">
        </div>

        <!-- Plan -->
        <div class="col-md-6">
          <label class="form-label">Plan</label>
          <select name="plan_id" id="plan_id"
                  class="form-select" required>
            <option value="">— select plan —</option>
            <?php foreach ($plans as $pl): ?>
              <option
                value="<?= $pl['id'] ?>"
                data-centre-id="<?= $pl['centre_id'] ?>"
                <?= ((int)$plan_val === (int)$pl['id']) ? 'selected':'' ?>>
                <?= htmlspecialchars($pl['plan_name']) ?> —
                <?= $pl['duration_months'] ?> m
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- DOB -->
        <div class="col-md-3">
          <label class="form-label">Date of Birth</label>
          <input name="dob" type="date" class="form-control"
                 value="<?= htmlspecialchars($dob_val) ?>">
        </div>

        <!-- Address -->
        <div class="col-md-9">
          <label class="form-label">Address</label>
          <input name="address" type="text"
                 class="form-control"
                 value="<?= htmlspecialchars($address_val) ?>">
        </div>

      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-success">
          Save Changes
        </button>
        <a href="index.php?page=students"
           class="btn btn-secondary ms-2">Cancel</a>
      </div>
    </form>
  </div>

  <script>
    // Filter the “Plan” dropdown to only show options matching the chosen centre
    const centreEl = document.getElementById('centre_id');
    const planEl   = document.getElementById('plan_id');

    function filterPlans() {
      const c = centreEl.value;
      for (let opt of planEl.options) {
        const cid = opt.dataset.centreId;
        if (!cid) {
          opt.hidden = false;
        } else {
          opt.hidden = (cid !== c);
        }
      }
      // clear selection if it’s now hidden
      if (planEl.selectedOptions.length && planEl.selectedOptions[0].hidden) {
        planEl.value = '';
      }
    }

    centreEl.addEventListener('change', filterPlans);
    document.addEventListener('DOMContentLoaded', filterPlans);
  </script>
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
