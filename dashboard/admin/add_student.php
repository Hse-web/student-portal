<?php
// dashboard/admin/add_student.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

//
// 1) Load centres for form
//
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

//
// 2) Handle form POST
//
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_stage'] ?? '') === 'submit') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $errors[] = 'Session expired—please reload.';
  }

  // collect inputs
  $email       = trim($_POST['email']      ?? '');
  $password    = $_POST['password']        ?? '';
  $name        = trim($_POST['name']       ?? '');
  $phone       = trim($_POST['phone']      ?? '');
  $centre_id   = (int)($_POST['centre_id'] ?? 0);
  $group_name  = trim($_POST['group_name'] ?? '');
  $plan_id     = (int)($_POST['plan_id']   ?? 0);
  $dob         = $_POST['dob']             ?? null;
  $address     = trim($_POST['address']    ?? '');
  $isLegacy    = !empty($_POST['is_legacy']) ? 1 : 0;
  $referrer    = trim($_POST['referrer']   ?? '');
  $applyRef    = ($referrer !== '');

  // validation
  if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Valid email required.';
  }
  if (strlen($password) < 6) {
    $errors[] = 'Password ≥ 6 chars.';
  }
  if ($name === '') {
    $errors[] = 'Full name required.';
  }
  if ($centre_id < 1) {
    $errors[] = 'Select a centre.';
  }
  if ($group_name === '') {
    $errors[] = 'Select a group.';
  }
  if ($plan_id < 1) {
    $errors[] = 'Select a plan.';
  }
  // if referrer entered, verify exists and has no dues
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
      // check no pending payments
      $p = $conn->prepare("
        SELECT COUNT(*) AS cnt
          FROM payments
         WHERE student_id = ?
           AND status <> 'Paid'
      ");
      $p->bind_param('i',$referrerId);
      $p->execute();
      $c = $p->get_result()->fetch_assoc()['cnt'];
      $p->close();
      if ($c>0) {
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
      // new student gets 5% discount if join on/before 15th
      $joinDay = (int)date('j');
      $discount = ($joinDay <= 15) ? 5.00 : 0.00;
      $s->bind_param(
        'issssssiiii',
        $userId, $name, $email, $phone, $group_name, $dob, $address,
        $centre_id, $isLegacy, $referrerId, $discount
      );
      $s->execute();
      $stuId = $s->insert_id;
      $s->close();

      // c) if we have a valid referrer, give them 10% next billing
      if ($applyRef && $referrerId) {
        $u2 = $conn->prepare("
          UPDATE students
             SET pending_discount_percent = 10.00
           WHERE id = ?
        ");
        $u2->bind_param('i',$referrerId);
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

      // e) compute initial fees (we’ll call our fee calculator)
      require_once __DIR__ . '/../includes/fee_calculator.php';
      $fee = calculate_student_fee($conn, $stuId, $plan_id, true, false);

      // f) record payment due
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
    } catch (Throwable $e) {
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
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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

  <form method="post" id="addStudentForm" class="row g-3">
    <input type="hidden" name="csrf_token"   value="<?= $csrf ?>">
    <input type="hidden" name="form_stage"    value="submit">

    <div class="col-md-6">
      <label>Email</label>
      <input name="email"     type="email"    required class="form-control"
             value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label>Password</label>
      <input name="password"  type="password" required class="form-control">
    </div>
    <div class="col-md-6">
      <label>Full Name</label>
      <input name="name"      type="text"     required class="form-control"
             value="<?=htmlspecialchars($_POST['name'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label>Phone</label>
      <input name="phone"     type="text"     class="form-control"
             value="<?=htmlspecialchars($_POST['phone'] ?? '')?>">
    </div>

    <div class="col-md-4">
      <label>Centre</label>
      <select id="centre" name="centre_id" class="form-select" required>
        <option value="">— Select Centre —</option>
        <?php foreach($centres as $c): ?>
          <option value="<?=$c['id']?>"
            <?=((int)($_POST['centre_id'] ?? 0) === $c['id']) ? 'selected' : ''?>>
            <?=htmlspecialchars($c['name'])?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label>Group</label>
      <select id="group" name="group_name" class="form-select" required>
        <option value="">— Select Centre First —</option>
        <?php if (!empty($_POST['centre_id'])): // on reload ?>
          <?php
          $stmt = $conn->prepare("
            SELECT DISTINCT group_name
              FROM payment_plans
             WHERE centre_id = ?
             ORDER BY group_name
          ");
          $stmt->bind_param('i', (int)$_POST['centre_id']);
          $stmt->execute();
          $rs = $stmt->get_result();
          while ($r = $rs->fetch_assoc()) {
            $sel = ($r['group_name'] === ($_POST['group_name'] ?? '')) ? 'selected' : '';
            $g = htmlspecialchars($r['group_name']);
            echo "<option {$sel} value=\"{$g}\">{$g}</option>";
          }
          $stmt->close();
          ?>
        <?php endif; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label>Plan</label>
      <select id="plan" name="plan_id" class="form-select" required>
        <option value="">— Select Group First —</option>
        <?php if (!empty($_POST['group_name'])): // on reload ?>
          <?php
          $stmt = $conn->prepare("
            SELECT id,
                   CONCAT(plan_name,' (',duration_months,'m) — ₹',amount) AS label
              FROM payment_plans
             WHERE centre_id = ? AND group_name = ?
             ORDER BY duration_months
          ");
          $stmt->bind_param('is', (int)$_POST['centre_id'], $_POST['group_name']);
          $stmt->execute();
          $rs = $stmt->get_result();
          while ($r = $rs->fetch_assoc()) {
            $sel   = ($r['id'] == ($_POST['plan_id'] ?? 0)) ? 'selected' : '';
            $label = htmlspecialchars($r['label']);
            echo "<option {$sel} value=\"{$r['id']}\">{$label}</option>";
          }
          $stmt->close();
          ?>
        <?php endif; ?>
      </select>
    </div>

    <div class="col-md-6">
      <label>Date of Birth</label>
      <input name="dob" type="date" class="form-control"
             value="<?=htmlspecialchars($_POST['dob'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label>Address</label>
      <input name="address" type="text" class="form-control"
             value="<?=htmlspecialchars($_POST['address'] ?? '')?>">
    </div>

    <div class="col-12 form-check">
      <input class="form-check-input" type="checkbox" id="is_legacy" name="is_legacy" value="1"
        <?=!empty($_POST['is_legacy'])?'checked':''?>>
      <label class="form-check-label" for="is_legacy">
        Skip one-time fees (already paid)
      </label>
    </div>

    <div class="col-md-6">
      <label>Referrer Email (optional)</label>
      <input name="referrer" type="email" class="form-control"
             value="<?=htmlspecialchars($_POST['referrer'] ?? '')?>">
      <small class="text-muted">
        If this student was referred, enter the referrer’s email.
      </small>
    </div>

    <div class="col-12 text-end">
      <button type="submit" class="btn btn-success">
        <i class="bi bi-check2-circle me-1"></i>Create Student
      </button>
      <a href="index.php?page=students" class="btn btn-secondary ms-2">Cancel</a>
    </div>
  </form>
</div>

<script>
$(document).ready(function() {
    // When Centre is changed, load the corresponding groups
    $('#centre').on('change', function() {
        const centreId = $(this).val();
        if (centreId) {
            // Show a loading placeholder in Group dropdown
            $('#group').html('<option value="">Loading...</option>');
            // Also reset the Plan dropdown
            $('#plan').html('<option value="">-- Select Group First --</option>');
            // AJAX request to fetch groups for the selected centre
            $.post('ajax/get_groups.php', { centre_id: centreId }, function(response) {
                // Populate the Group dropdown with the returned options
                $('#group').html(response);
            });
        } else {
            // If no centre selected, reset both dropdowns
            $('#group').html('<option value="">-- Select Centre First --</option>');
            $('#plan').html('<option value="">-- Select Group First --</option>');
        }
    });

    // When Group is changed, load the corresponding plans
    $('#group').on('change', function() {
        const groupName = $(this).val();
        const centreId  = $('#centre').val();
        if (groupName) {
            // Show a loading placeholder in Plan dropdown
            $('#plan').html('<option value="">Loading...</option>');
            // AJAX request to fetch plans for the selected centre & group
            $.post('ajax/get_plans.php', 
                   { centre_id: centreId, group_name: groupName }, 
                   function(response) {
                       // Populate the Plan dropdown with the returned options
                       $('#plan').html(response);
                   }
            );
        } else {
            // If no group selected, reset the Plan dropdown
            $('#plan').html('<option value="">-- Select Group First --</option>');
        }
    });

    // OPTIONAL: On page load, if a default Centre is pre-selected, trigger initial group load
    const initialCentre = $('#centre').val();
    if(initialCentre) {
        $('#group').html('<option value="">Loading...</option>');
        $('#plan').html('<option value="">-- Select Group First --</option>');
        $.post('ajax/get_groups.php', { centre_id: initialCentre }, function(response) {
            $('#group').html(response);
        });
    }
});
</script>


</body>
</html>
