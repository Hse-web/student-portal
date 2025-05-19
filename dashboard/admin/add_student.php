<?php
// File: dashboard/admin/add_student.php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── 1) Load Centres & Plans ─────────────────────────────────────────
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$plans = $conn
  ->query("
    SELECT 
      id, 
      centre_id, 
      CONCAT(plan_name,' (',duration_months,'m) — ₹',amount) AS label
    FROM payment_plans
    ORDER BY centre_id, duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

// ─── 2) Handle form submission ────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // a) CSRF
    if (! verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired. Please reload and try again.';
    }

    // b) Gather & trim
    $username   = trim($_POST['email']      ?? '');
    $password   = $_POST['password']        ?? '';
    $name       = trim($_POST['name']       ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $centre_id  = (int)($_POST['centre_id'] ?? 0);
    $group_name = trim($_POST['group_name'] ?? '');
    $plan_id    = (int)($_POST['plan_id']   ?? 0);
    $dob        = $_POST['dob']             ?? '';
    $address    = trim($_POST['address']    ?? '');
    $isLegacy   = !empty($_POST['is_legacy']) ? 1 : 0;

    // c) Validation
    if (! filter_var($username, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email is required.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($centre_id < 1) {
        $errors[] = 'Please select a centre.';
    }
    if ($group_name === '') {
        $errors[] = 'Please enter a group name.';
    }
    if ($plan_id < 1) {
        $errors[] = 'Please select a plan.';
    }

    // d) If valid, insert everything in a transaction
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // d.i) Create user
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("
              INSERT INTO users (username,password,role,centre_id)
              VALUES (?, ?, 'student', ?)
            ");
            $stmt->bind_param('ssi', $username, $hash, $centre_id);
            $stmt->execute();
            $userId = $stmt->insert_id;
            $stmt->close();

            // d.ii) Create student
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

            // d.iii) Subscribe them
            $now = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("
              INSERT INTO student_subscriptions
                (student_id,plan_id,subscribed_at)
              VALUES (?,?,?)
            ");
            $stmt->bind_param('iis', $studentId, $plan_id, $now);
            $stmt->execute();
            $stmt->close();

            // d.iv) Fetch fee settings & plan details
            $stmt = $conn->prepare("
              SELECT
                cfs.enrollment_fee,
                cfs.advance_fee,
                cfs.prorate_allowed,
                cfs.gst_percent,
                p.amount        AS plan_amt,
                p.duration_months,
                sub.subscribed_at
              FROM student_subscriptions sub
              JOIN payment_plans       p   ON p.id   = sub.plan_id
              JOIN center_fee_settings cfs ON cfs.centre_id = ?
              WHERE sub.student_id = ?
              ORDER BY sub.subscribed_at DESC
              LIMIT 1
            ");
            $stmt->bind_param('ii', $centre_id, $studentId);
            $stmt->execute();
            $stmt->bind_result(
              $enrollFee, $advanceFee,
              $prorateAllowed, $gstPct,
              $planAmt, $dur, $subAt
            );
            $stmt->fetch();
            $stmt->close();

            // d.v) Compute amounts
            $oneTime = $isLegacy ? 0 : ($enrollFee + $advanceFee);
            if ($dur === 1 && $prorateAllowed && $subAt) {
                $day = (int)(new DateTime($subAt))->format('j');
                if ($day > 15) $planAmt *= 0.5;
            }
            $subtotal  = $planAmt + $oneTime;
            $gstAmt    = round($subtotal * ($gstPct/100), 2);
            $amountDue = round($subtotal + $gstAmt, 2);

            // d.vi) Create payment
            $stmt = $conn->prepare("
              INSERT INTO payments
                (student_id,status,amount_paid,amount_due,paid_at)
              VALUES (?, 'Pending', 0.00, ?, NULL)
            ");
            $stmt->bind_param('id', $studentId, $amountDue);
            $stmt->execute();
            $stmt->close();

            // d.vii) Commit
            $conn->commit();

            // d.viii) Audit log
            log_audit(
              $conn,
              $_SESSION['user_id'],
              'INSERT',
              'students',
              $studentId,
              ['new'=>[
                'id'=>$studentId,
                'user_id'=>$user_id,
                'name'=>$name,
                'email'=>$username,
                'centre_id'=>$centre_id,
                'group_name'=>$group_name
              ]]
            );

            // d.ix) Redirect
            $_SESSION['flash_success'] = 'Student created successfully.';
            header('Location: index.php?page=students');
            exit;
        }
        catch (Throwable $e) {
            $conn->rollback();
            $errors[] = 'Error creating student: '.$e->getMessage();
        }
    }
}

// ─── 3) Render form ─────────────────────────────────────────────────
$csrf = generate_csrf_token();
?>
<h2 class="section-header">Add New Student</h2>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="post" action="?page=add_student" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

      <div class="col-md-6">
        <label class="form-label">Email (username)</label>
        <input type="email" name="email" required class="form-control"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Password</label>
        <input type="password" name="password" required class="form-control">
      </div>
      <div class="col-md-6">
        <label class="form-label">Full Name</label>
        <input type="text" name="name" required class="form-control"
               value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control"
               value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Centre</label>
        <select id="centre_id" name="centre_id" required
                class="form-select">
          <option value="">— Select Centre —</option>
          <?php foreach ($centres as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= ((int)($_POST['centre_id'] ?? 0) === $c['id']) ? 'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Group</label>
        <input type="text" name="group_name" required class="form-control"
               value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Plan</label>
        <select id="plan_id" name="plan_id" required class="form-select">
          <option value="">— Select Plan —</option>
          <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>"
                  data-centre-id="<?= $p['centre_id'] ?>"
            <?= ((int)($_POST['plan_id'] ?? 0) === $p['id']) ? 'selected':'' ?>>
            <?= htmlspecialchars($p['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Date of Birth</label>
        <input type="date" name="dob" class="form-control"
               value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Address</label>
        <input type="text" name="address" class="form-control"
               value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
      </div>
      <div class="col-12 form-check">
        <input class="form-check-input" type="checkbox"
               id="is_legacy" name="is_legacy" value="1"
          <?= !empty($_POST['is_legacy']) ? 'checked':'' ?>>
        <label class="form-check-label" for="is_legacy">
          Already paid enrollment/advance (skip one-time fees)
        </label>
      </div>
      <div class="col-12 text-end">
        <button type="submit" class="btn btn-success">
          <i class="bi bi-check2-circle me-1"></i>Create Student
        </button>
        <a href="index.php?page=students" class="btn btn-secondary ms-2">
          Cancel
        </a>
      </div>
    </form>
  </div>
</div>

<script>
  // Filter Plan dropdown by selected Centre
  const centreEl = document.getElementById('centre_id'),
        planEl   = document.getElementById('plan_id');
  function filterPlans() {
    const cid = centreEl.value;
    Array.from(planEl.options).forEach(o => {
      o.hidden = o.value && (o.dataset.centreId !== cid);
    });
    if (planEl.selectedOptions[0]?.hidden) planEl.value = '';
  }
  centreEl.addEventListener('change', filterPlans);
  document.addEventListener('DOMContentLoaded', filterPlans);
</script>
