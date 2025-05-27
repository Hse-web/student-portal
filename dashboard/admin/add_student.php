<?php
// dashboard/admin/add_student.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/fee_calculator.php';
require_once __DIR__ . '/../includes/can_student_subscribe.php';

//
// 1) Load centres, groups & plans (for JS cascade)
//
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$all_groups = $conn
  ->query("SELECT DISTINCT centre_id, group_name FROM payment_plans ORDER BY group_name")
  ->fetch_all(MYSQLI_ASSOC);

$all_plans = $conn
  ->query("
    SELECT id, centre_id, group_name,
           CONCAT(plan_name,' (',duration_months,'m) — ₹',amount) AS label
      FROM payment_plans
     ORDER BY duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

//
// 2) Handle POST
//
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_stage'] ?? '') === 'submit') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Session expired—please reload.';
  }

  // collect inputs
  $email      = trim($_POST['email']      ?? '');
  $password   = $_POST['password']        ?? '';
  $name       = trim($_POST['name']       ?? '');
  $phone      = trim($_POST['phone']      ?? '');
  $centre_id  = (int)($_POST['centre_id'] ?? 0);
  $group_name = trim($_POST['group_name'] ?? '');
  $plan_id    = (int)($_POST['plan_id']   ?? 0);
  $dob        = $_POST['dob']             ?? null;
  $address    = trim($_POST['address']    ?? '');
  // two separate skip‐fee checkboxes
  $skipEnroll  = !empty($_POST['skip_enroll_fee'])  ? 1 : 0;
  $skipAdvance = !empty($_POST['skip_advance_fee']) ? 1 : 0;
  // legacy if either skip checked
  $isLegacy = ($skipEnroll || $skipAdvance) ? 1 : 0;
  $referrer   = trim($_POST['referrer']   ?? '');
  $applyRef   = ($referrer !== '');

  // validation
  if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
  if (strlen($password) < 6)                      $errors[] = 'Password ≥ 6 chars.';
  if ($name === '')                               $errors[] = 'Full name required.';
  if ($centre_id < 1)                             $errors[] = 'Select a centre.';
  if ($group_name === '')                         $errors[] = 'Select a group.';
  if ($plan_id < 1)                               $errors[] = 'Select a plan.';

  // referrer check
  $referrerId = null;
  if ($applyRef) {
    $q = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
    $q->bind_param('s', $referrer);
    $q->execute();
    $res = $q->get_result()->fetch_assoc();
    $q->close();
    if (! $res) {
      $errors[] = 'Referrer not found.';
    } else {
      $referrerId = (int)$res['id'];
      $p = $conn->prepare("
        SELECT COUNT(*) AS cnt
          FROM payments
         WHERE student_id = ? AND status <> 'Paid'
      ");
      $p->bind_param('i', $referrerId);
      $p->execute();
      $cnt = $p->get_result()->fetch_assoc()['cnt'];
      $p->close();
      if ($cnt > 0) {
        $errors[] = 'Referrer has outstanding dues.';
      }
    }
  }

  if (empty($errors)) {
    $conn->begin_transaction();
    try {
      // a) create user
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $u = $conn->prepare("
        INSERT INTO users (username,password,role,centre_id)
        VALUES (?, ?, 'student', ?)
      ");
      $u->bind_param('ssi', $email, $hash, $centre_id);
      $u->execute();
      $userId = $u->insert_id;
      $u->close();

      // b) create student
      $s = $conn->prepare("
        INSERT INTO students
          (user_id,name,email,phone,group_name,dob,address,centre_id,is_legacy,referred_by,pending_discount_percent)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
      ");
      // referral discount if joinDay ≤ 15
      $joinDay  = (int)date('j');
      $discount = ($joinDay <= 15) ? 5.00 : 0.00;
      $s->bind_param(
        'issssssiiii',
        $userId, $name, $email, $phone, $group_name, $dob, $address,
        $centre_id, $isLegacy, $referrerId, $discount
      );
      $s->execute();
      $stuId = $s->insert_id;
      $s->close();

      // c) reward referrer
      if ($applyRef && $referrerId) {
        $u2 = $conn->prepare("
          UPDATE students
             SET pending_discount_percent = 10.00
           WHERE id = ?
        ");
        $u2->bind_param('i', $referrerId);
        $u2->execute();
        $u2->close();
      }

      // d) subscription
      $now = date('Y-m-d H:i:s');
      $sub = $conn->prepare("
        INSERT INTO student_subscriptions
          (student_id,plan_id,subscribed_at)
        VALUES (?,?,?)
      ");
      $sub->bind_param('iis', $stuId, $plan_id, $now);
      $sub->execute();
      $sub->close();

      // e) compute fee
      $fee = calculate_student_fee($conn, $stuId, $plan_id, true, false);

      // f) payments
      $pay = $conn->prepare("
        INSERT INTO payments
          (student_id,status,amount_paid,amount_due,paid_at)
        VALUES (?, 'Pending', 0.00, ?, NULL)
      ");
      $pay->bind_param('id', $stuId, $fee['total']);
      $pay->execute();
      $pay->close();

      $conn->commit();
      $_SESSION['flash_success'] = 'Student created successfully.';
      header('Location: index.php?page=students');
      exit;
    } catch (\Throwable $e) {
      $conn->rollback();
      $errors[] = 'Error: ' . $e->getMessage();
    }
  }
}

// CSRF token
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Add New Student</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<div class="container py-4">
  <h2>Add New Student</h2>

  <?php if ($errors): ?>
    <div class="alert alert-danger"><ul>
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul></div>
  <?php endif; ?>

  <form method="post" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="form_stage"  value="submit">

    <div class="col-md-6">
      <label class="form-label">Email</label>
      <input
        name="email" type="email" required
        class="form-control"
        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
      >
    </div>

    <div class="col-md-6">
      <label class="form-label">Password</label>
      <input name="password" type="password" required class="form-control">
    </div>

    <div class="col-md-6">
      <label class="form-label">Full Name</label>
      <input
        name="name" type="text" required
        class="form-control"
        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
      >
    </div>

    <div class="col-md-6">
      <label class="form-label">Phone</label>
      <input
        name="phone" type="text"
        class="form-control"
        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
      >
    </div>

    <div class="col-md-4">
      <label class="form-label">Centre</label>
      <select id="centre" name="centre_id" class="form-select" required>
        <option value="">— Select Centre —</option>
        <?php foreach($centres as $c): ?>
          <option
            value="<?= $c['id'] ?>"
            <?= ((int)($_POST['centre_id'] ?? 0) === $c['id']) ? 'selected' : '' ?>
          ><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Group</label>
      <select id="group" name="group_name" class="form-select" required disabled>
        <option>— Select Centre First —</option>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label">Plan</label>
      <select id="plan" name="plan_id" class="form-select" required disabled>
        <option>— Select Group First —</option>
      </select>
    </div>

    <div class="col-md-6">
      <label class="form-label">Date of Birth</label>
      <input
        name="dob" type="date"
        class="form-control"
        value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>"
      >
    </div>

    <div class="col-md-6">
      <label class="form-label">Address</label>
      <input
        name="address" type="text"
        class="form-control"
        value="<?= htmlspecialchars($_POST['address'] ?? '') ?>"
      >
    </div>

    <div class="col-md-6 form-check">
      <input
        class="form-check-input" type="checkbox"
        id="skipEnroll" name="skip_enroll_fee" value="1"
        <?= !empty($_POST['skip_enroll_fee']) ? 'checked' : '' ?>
      >
      <label class="form-check-label" for="skipEnroll">
        Skip Enrollment Fee (already paid)
      </label>
    </div>

    <div class="col-md-6 form-check">
      <input
        class="form-check-input" type="checkbox"
        id="skipAdvance" name="skip_advance_fee" value="1"
        <?= !empty($_POST['skip_advance_fee']) ? 'checked' : '' ?>
      >
      <label class="form-check-label" for="skipAdvance">
        Skip Advance Fee (already paid)
      </label>
    </div>

    <div class="col-md-6">
      <label class="form-label">Referrer Email (optional)</label>
      <input
        name="referrer" type="email"
        class="form-control"
        value="<?= htmlspecialchars($_POST['referrer'] ?? '') ?>"
      >
      <small class="text-muted">
        If referred, enter the referrer’s email.
      </small>
    </div>

    <div class="col-12 text-end">
      <button class="btn btn-success">
        Create Student
      </button>
      <a href="index.php?page=students" class="btn btn-secondary ms-2">Cancel</a>
    </div>
  </form>
</div>

<script>
// Preloaded data
const GROUPS = <?= json_encode($all_groups, JSON_UNESCAPED_UNICODE) ?>;
const PLANS  = <?= json_encode($all_plans,  JSON_UNESCAPED_UNICODE) ?>;

// Elements
const centreEl = $('#centre'),
      groupEl  = $('#group'),
      planEl   = $('#plan');

function populateGroups() {
  const cid = centreEl.val();
  groupEl.prop('disabled', !cid);
  planEl.html('<option>— Select Group First —</option>').prop('disabled', true);

  let opts = GROUPS
    .filter(x => x.centre_id == cid)
    .map(x => `<option>${x.group_name}</option>`);
  groupEl.html(opts.length
    ? `<option value="">— Select Group —</option>${opts.join('')}`
    : `<option value="">(no groups)</option>`
  );
}

function populatePlans() {
  const cid = centreEl.val(),
        grp = groupEl.val();

  planEl.prop('disabled', !grp);
  let opts = PLANS
    .filter(x => x.centre_id == cid && x.group_name === grp)
    .map(x => `<option value="${x.id}">${x.label}</option>`);

  planEl.html(opts.length
    ? `<option value="">— Select Plan —</option>${opts.join('')}`
    : `<option value="">(no plans)</option>`
  );
}

centreEl.change(populateGroups);
groupEl.change(populatePlans);

// re-populate if form reloaded
if (centreEl.val()) populateGroups();
if (groupEl.val())  populatePlans();
</script>
</body>
</html>
