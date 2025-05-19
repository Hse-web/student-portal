<?php
// File: dashboard/admin/add_student.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php'; // provides $conn

// ─── 1) Load centres & payment plans for the form ───────────────────
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$plans = $conn
  ->query("
    SELECT id, centre_id, group_name, plan_name, duration_months, amount
      FROM payment_plans
     ORDER BY centre_id, group_name, duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

$errors = [];

// ─── 2) Handle form submission ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // 2a) Gather & trim inputs
  $username   = trim($_POST['email']       ?? '');
  $password   = $_POST['password']         ?? '';
  $name       = trim($_POST['name']        ?? '');
  $phone      = trim($_POST['phone']       ?? '');
  $centre_id  = (int)($_POST['centre_id']  ?? 0);
  $group_name = trim($_POST['group_name']  ?? '');
  $plan_id    = (int)($_POST['plan_id']    ?? 0);
  $dob        = $_POST['dob']              ?? '';
  $address    = trim($_POST['address']     ?? '');
  $isLegacy   = (!empty($_POST['is_legacy'])) ? 1 : 0;

  // 2b) Validation
  if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'A valid email is required.';
  }
  if (strlen($password) < 6) {
    $errors[] = 'Password must be at least 6 characters.';
  }
  if ($name === '') {
    $errors[] = 'Full name is required.';
  }
  if (! $centre_id) {
    $errors[] = 'Please select a centre.';
  }
  if ($group_name === '') {
    $errors[] = 'Please enter a group name.';
  }
  if (! $plan_id) {
    $errors[] = 'Please select a plan.';
  }

  // 2c) If no errors, proceed to insert
  if (empty($errors)) {
    $conn->begin_transaction();
    try {
      // 2c.i) Create user account
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("
        INSERT INTO users (username,password,role,centre_id)
        VALUES (?, ?, 'student', ?)
      ");
      $stmt->bind_param('ssi', $username, $hash, $centre_id);
      $stmt->execute();
      $userId = $stmt->insert_id;
      $stmt->close();

      // 2c.ii) Create student record, including is_legacy flag
      $stmt = $conn->prepare("
        INSERT INTO students
          (user_id,name,email,phone,group_name,dob,address,centre_id,is_legacy)
        VALUES (?,?,?,?,?,?,?,?,?)
      ");
      $stmt->bind_param(
        'issssssii',
        $userId,
        $name,
        $username,
        $phone,
        $group_name,
        $dob,
        $address,
        $centre_id,
        $isLegacy
      );
      $stmt->execute();
      $studentId = $stmt->insert_id;
      $stmt->close();

      // 2c.iii) Subscribe them immediately
      $now = date('Y-m-d H:i:s');
      $stmt = $conn->prepare("
        INSERT INTO student_subscriptions
          (student_id, plan_id, subscribed_at)
        VALUES (?, ?, ?)
      ");
      $stmt->bind_param('iis', $studentId, $plan_id, $now);
      $stmt->execute();
      $stmt->close();

      // 2c.iv) Fetch centre‐fee settings and plan details
      $stmt = $conn->prepare("
        SELECT
          cfs.enrollment_fee,
          cfs.advance_fee,
          cfs.prorate_allowed,
          cfs.gst_percent,
          p.amount          AS plan_amt,
          p.duration_months,
          sub.subscribed_at
        FROM student_subscriptions sub
        JOIN payment_plans        p   ON p.id = sub.plan_id
        JOIN center_fee_settings  cfs ON cfs.centre_id = ?
       WHERE sub.student_id = ?
       ORDER BY sub.subscribed_at DESC
       LIMIT 1
      ");
      $stmt->bind_param('ii', $centre_id, $studentId);
      $stmt->execute();
      $stmt->bind_result(
        $enrollFee,
        $advanceFee,
        $prorateAllowed,
        $gstPct,
        $planAmt,
        $dur,
        $subAt
      );
      $stmt->fetch();
      $stmt->close();

      // 2c.v) Determine one-time fees (skip if legacy)
      $oneTime = $isLegacy
        ? 0
        : ($enrollFee + $advanceFee);

      // 2c.vi) Apply prorate on 1-month plans
      if ($dur === 1 && $prorateAllowed && $subAt) {
        $day = (int)(new DateTime($subAt))->format('j');
        if ($day > 15) {
          $planAmt *= 0.5;
        }
      }

      // 2c.vii) Compute total due
      $subtotal  = $planAmt + $oneTime;
      $gstAmt    = round($subtotal * ($gstPct/100), 2);
      $amountDue = round($subtotal + $gstAmt, 2);

      // 2c.viii) Create payment row
      $stmt = $conn->prepare("
        INSERT INTO payments
          (student_id, status, amount_paid, amount_due, paid_at)
        VALUES (?, 'Pending', 0.00, ?, NULL)
      ");
      $stmt->bind_param('id', $studentId, $amountDue);
      $stmt->execute();
      $stmt->close();

      // 2c.ix) Commit & redirect
      $conn->commit();
      header('Location: index.php?page=students&msg=created');
      exit;

    } catch (Throwable $e) {
      $conn->rollback();
      $errors[] = 'Error creating student: ' . $e->getMessage();
    }
  }
}
?>

<!-- ─── 3) Render form ──────────────────────────────────────────────── -->
<h2 class="section-header">Add New Student</h2>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="?page=add_student">
  <div class="row g-3">
    <!-- Email & Password -->
    <div class="col-md-6">
      <label class="form-label">Email (username)</label>
      <input type="email" name="email" class="form-control" required
             value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Password</label>
      <input type="password" name="password" class="form-control" required
             value="<?=htmlspecialchars($_POST['password'] ?? '')?>">
    </div>

    <!-- Name & Phone -->
    <div class="col-md-6">
      <label class="form-label">Full Name</label>
      <input name="name" class="form-control" required
             value="<?=htmlspecialchars($_POST['name'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Phone</label>
      <input name="phone" class="form-control"
             value="<?=htmlspecialchars($_POST['phone'] ?? '')?>">
    </div>

    <!-- Centre, Group, Plan -->
    <div class="col-md-4">
      <label class="form-label">Centre</label>
      <select name="centre_id" class="form-select" required>
        <option value="">— select centre —</option>
        <?php foreach ($centres as $c): ?>
          <option value="<?=$c['id']?>"
            <?=((int)($_POST['centre_id']??0)===$c['id'])?'selected':''?>>
            <?=htmlspecialchars($c['name'])?>
          </option>
        <?php endforeach;?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Group</label>
      <input name="group_name" class="form-control" required
             value="<?=htmlspecialchars($_POST['group_name'] ?? '')?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Plan</label>
      <select name="plan_id" class="form-select" required>
        <option value="">— select plan —</option>
        <?php foreach ($plans as $pl): ?>
          <option
            value="<?=$pl['id']?>"
            data-centre-id="<?=$pl['centre_id']?>"
            <?=((int)($_POST['plan_id']??0)===$pl['id'])?'selected':''?>>
            <?=htmlspecialchars($pl['plan_name'])?>
            — <?=$pl['duration_months']?>m / ₹<?=number_format($pl['amount'],2)?>
          </option>
        <?php endforeach;?>
      </select>
    </div>

    <!-- DOB & Address -->
    <div class="col-md-6">
      <label class="form-label">Date of Birth</label>
      <input type="date" name="dob" class="form-control"
        value="<?=htmlspecialchars($_POST['dob'] ?? '')?>">
    </div>
    <div class="col-md-6">
      <label class="form-label">Address</label>
      <input name="address" class="form-control"
        value="<?=htmlspecialchars($_POST['address'] ?? '')?>">
    </div>

    <!-- Legacy checkbox -->
    <div class="col-md-6 form-check mt-3">
      <input
        class="form-check-input"
        type="checkbox"
        name="is_legacy"
        id="is_legacy"
        value="1"
        <?=(!empty($_POST['is_legacy']))?'checked':''?>
      >
      <label class="form-check-label" for="is_legacy">
        Already paid enrollment/advance (skip one-time fees)
      </label>
    </div>
  </div>

  <!-- Submit buttons -->
  <div class="mt-4">
    <button class="btn btn-success">Create Student</button>
    <a href="index.php?page=students" class="btn btn-secondary ms-2">Cancel</a>
  </div>
</form>

<script>
// Filter plans by centre
const centreEl = document.getElementById('centre_id'),
      planEl   = document.querySelector('select[name="plan_id"]');
function filterPlans() {
  const c = centreEl.value;
  Array.from(planEl.options).forEach(o=>{
    o.style.display = (!o.value || o.dataset.centreId===c) ? '' : 'none';
  });
  if (planEl.selectedOptions[0]?.style.display==='none') planEl.value='';
}
centreEl.addEventListener('change', filterPlans);
document.addEventListener('DOMContentLoaded', filterPlans);
</script>
