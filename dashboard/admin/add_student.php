<?php
// File: dashboard/admin/add_student.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fee_calculator.php';

// 1) Load lookup lists
$centres   = $conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$plans     = $conn->query("
  SELECT id, centre_id,
         CONCAT(plan_name,' (',duration_months,'m) — ₹',amount) AS label
    FROM payment_plans
   ORDER BY centre_id, duration_months
")->fetch_all(MYSQLI_ASSOC);
$artGroups = $conn->query("SELECT id,label FROM art_groups ORDER BY sort_order")
                  ->fetch_all(MYSQLI_ASSOC);

// 2) Handle submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Session expired. Please reload and try again.';
  }

  // sanitize
  $email      = trim($_POST['email']      ?? '');
  $password   = $_POST['password']        ?? '';
  $name       = trim($_POST['name']       ?? '');
  $phone      = trim($_POST['phone']      ?? '');
  $centre_id  = (int)($_POST['centre_id'] ?? 0);
  $plan_id    = (int)($_POST['plan_id']   ?? 0);
  $initial_id = (int)($_POST['initial_group_id'] ?? 0);
  $skipE      = !empty($_POST['skip_enroll_fee']);
  $skipA      = !empty($_POST['skip_advance_fee']);
  $referrer   = trim($_POST['referrer']   ?? '');

  // validate
  if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
  if (strlen($password) < 6)                      $errors[] = 'Password must be at least 6 characters.';
  if ($name === '')                               $errors[] = 'Full name is required.';
  if ($centre_id < 1)                             $errors[] = 'Please select a centre.';
  if ($plan_id   < 1)                             $errors[] = 'Please select a payment plan.';
  if ($initial_id< 1)                             $errors[] = 'Please select an initial stage.';

  // optional referrer
  $refId = null;
  if ($referrer !== '') {
    $q   = $conn->prepare("SELECT id FROM students WHERE email=? LIMIT 1");
    $q->bind_param('s',$referrer);
    $q->execute();
    $tmp = $q->get_result()->fetch_assoc();
    $q->close();
    if (! $tmp) {
      $errors[] = 'Referrer not found.';
    } else {
      $refId = (int)$tmp['id'];
      $p = $conn->prepare("SELECT COUNT(*) cnt FROM payments WHERE student_id=? AND status<>'Paid'");
      $p->bind_param('i',$refId);
      $p->execute();
      $cnt = (int)$p->get_result()->fetch_assoc()['cnt'];
      $p->close();
      if ($cnt > 0) $errors[] = 'Referrer has outstanding dues.';
    }
  }

  if (empty($errors)) {
    $conn->begin_transaction();
    try {
      // a) Create user
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $u    = $conn->prepare("INSERT INTO users (username,password,role,centre_id) VALUES (?,?, 'student',?)");
      $u->bind_param('ssi', $email, $hash, $centre_id);
      $u->execute(); $userId = $u->insert_id; $u->close();

      // b) Lookup the initial stage label
      $g = $conn->prepare("SELECT label FROM art_groups WHERE id=? LIMIT 1");
      $g->bind_param('i',$initial_id);
      $g->execute(); $g->bind_result($initialLabel); $g->fetch(); $g->close();

      // c) Create student (no auto join-day discount)
      $disc = 0.00;
      $s = $conn->prepare("
        INSERT INTO students
        (user_id,name,email,phone,group_name,centre_id,
         skip_enroll_fee,skip_advance_fee,referred_by,pending_discount_percent)
        VALUES (?,?,?,?,?,?,
                ?,?,?,?)
      ");
      $s->bind_param(
        'issssiiiid',
        $userId,
        $name,
        $email,
        $phone,
        $initialLabel,
        $centre_id,
        $skipE,
        $skipA,
        $refId,
        $disc
      );
      $s->execute(); $stuId = $s->insert_id; $s->close();

      // d) Reward referrer if present
      if ($refId) {
        $r = $conn->prepare("UPDATE students SET pending_discount_percent=10.00 WHERE id=?");
        $r->bind_param('i',$refId); $r->execute(); $r->close();
      }

      // e) Subscription
      $now = date('Y-m-d H:i:s');
      $sub= $conn->prepare("INSERT INTO student_subscriptions (student_id,plan_id,subscribed_at) VALUES (?,?,?)");
      $sub->bind_param('iis',$stuId,$plan_id,$now);
      $sub->execute(); $sub->close();

      // f) Payment due
      $amt = calculate_student_fee($conn,$stuId,$plan_id,true,false)['total'];
      $pay= $conn->prepare("INSERT INTO payments (student_id,status,amount_paid,amount_due) VALUES(?, 'Pending', 0.00, ?)");
      $pay->bind_param('id',$stuId,$amt);
      $pay->execute(); $pay->close();

      // g) Seed promotion
      $pr = $conn->prepare("
        INSERT INTO student_promotions
          (student_id,art_group_id,effective_date,is_applied)
        VALUES (?, ?, CURDATE(), 1)
      ");
      $pr->bind_param('ii',$stuId,$initial_id);
      $pr->execute(); $pr->close();

      $conn->commit();
      set_flash('Student created successfully.','success');
      header('Location:index.php?page=students');
      exit;
    } catch (\Throwable $e) {
      $conn->rollback();
      $errors[] = 'Error creating student: '.$e->getMessage();
    }
  }
}

// 3) CSRF + prefill
$csrf = generate_csrf_token();
$form = [
  'email'       => htmlspecialchars($_POST['email']      ?? '', ENT_QUOTES),
  'name'        => htmlspecialchars($_POST['name']       ?? '', ENT_QUOTES),
  'phone'       => htmlspecialchars($_POST['phone']      ?? '', ENT_QUOTES),
  'centre_id'   => (int)($_POST['centre_id']  ?? 0),
  'plan_id'     => (int)($_POST['plan_id']    ?? 0),
  'initial'     => (int)($_POST['initial_group_id'] ?? 0),
  'skip_enroll' => !empty($_POST['skip_enroll_fee']),
  'skip_advance'=> !empty($_POST['skip_advance_fee']),
  'referrer'    => htmlspecialchars($_POST['referrer']   ?? '', ENT_QUOTES),
];
?>
<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-6">
  <div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-2xl font-bold mb-6">➕ Add New Student</h2>

    <?php if ($flash = get_flash()): ?>
      <div class="mb-4 p-4 bg-green-100 border-green-400 text-green-700 rounded">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="mb-4 p-4 bg-red-100 border-red-400 text-red-700 rounded">
        <ul class="list-disc pl-5">
          <?php foreach ($errors as $err): ?>
            <li><?= htmlspecialchars($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="form_stage"  value="submit">

      <!-- Email & Password -->
      <div class="sm:col-span-2 lg:col-span-3">
        <label class="block mb-1">Email</label>
        <input type="email" name="email" required
               class="w-full border rounded px-3 py-2"
               value="<?= $form['email'] ?>">
      </div>
      <div class="sm:col-span-2 lg:col-span-3">
        <label class="block mb-1">Password</label>
        <input type="password" name="password" required
               class="w-full border rounded px-3 py-2">
      </div>

      <!-- Name & Phone -->
      <div>
        <label class="block mb-1">Full Name</label>
        <input type="text" name="name" required
               class="w-full border rounded px-3 py-2"
               value="<?= $form['name'] ?>">
      </div>
      <div>
        <label class="block mb-1">Phone</label>
        <input type="text" name="phone"
               class="w-full border rounded px-3 py-2"
               value="<?= $form['phone'] ?>">
      </div>

      <!-- Initial Stage -->
      <div class="sm:col-span-2">
        <label class="block mb-1">Initial Stage</label>
        <select name="initial_group_id" required
                class="w-full border rounded px-3 py-2">
          <option value="">— Select Stage —</option>
          <?php foreach ($artGroups as $g): ?>
            <option value="<?= $g['id'] ?>"
              <?= $form['initial'] === $g['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($g['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Centre & Plan -->
      <div>
        <label class="block mb-1">Centre</label>
        <select name="centre_id" required
                class="w-full border rounded px-3 py-2">
          <option value="">— Centre —</option>
          <?php foreach ($centres as $c): ?>
            <option value="<?= $c['id'] ?>"
              <?= $form['centre_id'] === $c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block mb-1">Payment Plan</label>
        <select name="plan_id" required
                class="w-full border rounded px-3 py-2">
          <option value="">— Plan —</option>
          <?php foreach ($plans as $p): ?>
            <option value="<?= $p['id'] ?>"
              data-centre-id="<?= $p['centre_id'] ?>"
              <?= $form['plan_id'] === $p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Skip Fees -->
      <div class="flex items-center">
        <input id="skipEnroll" name="skip_enroll_fee" type="checkbox" value="1"
               <?= $form['skip_enroll'] ? 'checked' : '' ?> class="mr-2">
        <label for="skipEnroll">Skip Enrollment Fee</label>
      </div>
      <div class="flex items-center">
        <input id="skipAdvance" name="skip_advance_fee" type="checkbox" value="1"
               <?= $form['skip_advance'] ? 'checked' : '' ?> class="mr-2">
        <label for="skipAdvance">Skip Advance Fee</label>
      </div>

      <!-- Referrer -->
      <div class="sm:col-span-2">
        <label class="block mb-1">Referrer Email (optional)</label>
        <input type="email" name="referrer"
               class="w-full border rounded px-3 py-2"
               value="<?= $form['referrer'] ?>">
        <p class="text-sm text-gray-500 mt-1">If referred, enter their email.</p>
      </div>

      <!-- Submit / Cancel -->
      <div class="sm:col-span-2 text-right space-x-4">
        <button type="submit"
                class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
          Create Student
        </button>
        <a href="index.php?page=students"
           class="text-gray-700 hover:underline">Cancel</a>
      </div>
    </form>
  </div>
</div>

<script>
  // Filter the Plan dropdown when Centre changes
  document.querySelector('select[name=centre_id]')
    .addEventListener('change', e => {
      const cid = e.target.value;
      document.querySelectorAll('select[name=plan_id] option').forEach(opt => {
        opt.style.display = opt.dataset.centreId === cid ? '' : 'none';
      });
    });
</script>
