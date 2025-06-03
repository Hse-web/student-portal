<?php
// File: dashboard/admin/add_student.php

/**
 * This fragment is included by index.php?page=add_student.
 * It assumes the <head> and <body>…<main> tags are already output by the global layout.
 */

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── IMPORTANT: re-include your fee calculator helper ───────────────
require_once __DIR__ . '/../includes/fee_calculator.php';
// (Optional: if you have a can_student_subscribe helper, include it here too)
// require_once __DIR__ . '/../includes/can_student_subscribe.php';

// ─── 1) LOAD CENTRES, GROUPS & PLANS FOR JS CASCADE ────────────────
$centres = $conn
  ->query("SELECT id, name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$all_groups = $conn
  ->query("SELECT DISTINCT centre_id, group_name FROM payment_plans ORDER BY centre_id, group_name")
  ->fetch_all(MYSQLI_ASSOC);

$all_plans = $conn
  ->query("
    SELECT 
      id, 
      centre_id, 
      group_name,
      CONCAT(plan_name, ' (', duration_months, 'm) — ₹', amount) AS label
    FROM payment_plans
    ORDER BY centre_id, duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

// ─── 2) HANDLE POST ─────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_stage'] ?? '') === 'submit') {
    // CSRF
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expired—please reload and try again.';
    }

    // Collect inputs
    $email      = trim($_POST['email']      ?? '');
    $password   = $_POST['password']        ?? '';
    $name       = trim($_POST['name']       ?? '');
    $phone      = trim($_POST['phone']      ?? '');
    $centre_id  = (int) ($_POST['centre_id'] ?? 0);
    $group_name = trim($_POST['group_name'] ?? '');
    $plan_id    = (int) ($_POST['plan_id']   ?? 0);
    $dob        = trim($_POST['dob']        ?? '');
    $address    = trim($_POST['address']    ?? '');
    $skipEnroll  = !empty($_POST['skip_enroll_fee'])  ? 1 : 0;
    $skipAdvance = !empty($_POST['skip_advance_fee']) ? 1 : 0;
    $isLegacy   = ($skipEnroll || $skipAdvance) ? 1 : 0;
    $referrer   = trim($_POST['referrer']   ?? '');

    // Validation
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
        $errors[] = 'Please select a group.';
    }
    if ($plan_id < 1) {
        $errors[] = 'Please select a plan.';
    }

    // Check referrer if provided
    $referrerId = null;
    if ($referrer !== '') {
        $q = $conn->prepare("SELECT id FROM students WHERE email = ? LIMIT 1");
        $q->bind_param('s', $referrer);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        $q->close();
        if (! $row) {
            $errors[] = 'Referrer not found.';
        } else {
            $referrerId = (int)$row['id'];
            // Ensure referrer has no outstanding payments
            $p = $conn->prepare("
              SELECT COUNT(*) AS cnt
                FROM payments
               WHERE student_id = ? AND status <> 'Paid'
            ");
            $p->bind_param('i', $referrerId);
            $p->execute();
            $cnt = (int)$p->get_result()->fetch_assoc()['cnt'];
            $p->close();
            if ($cnt > 0) {
                $errors[] = 'Referrer has outstanding dues.';
            }
        }
    }

    // If no errors so far, proceed to INSERT
    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // a) CREATE user record
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $u = $conn->prepare("
              INSERT INTO users (username,password,role,centre_id)
              VALUES (?, ?, 'student', ?)
            ");
            $u->bind_param('ssi', $email, $hash, $centre_id);
            $u->execute();
            $userId = $u->insert_id;
            $u->close();

            // b) CREATE student record
            // Calculate referral discount: 5% if joining day ≤ 15
            $joinDay  = (int) date('j');
            $discount = ($joinDay <= 15) ? 5.00 : 0.00;
            $s = $conn->prepare("
              INSERT INTO students
                (user_id,name,email,phone,group_name,dob,address,centre_id,is_legacy,referred_by,pending_discount_percent)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $s->bind_param(
                'issssssiiii',
                $userId,
                $name,
                $email,
                $phone,
                $group_name,
                $dob,
                $address,
                $centre_id,
                $isLegacy,
                $referrerId,
                $discount
            );
            $s->execute();
            $stuId = $s->insert_id;
            $s->close();

            // c) If there was a valid referrer, reward them (10% discount)
            if ($referrerId) {
                $u2 = $conn->prepare("
                  UPDATE students
                     SET pending_discount_percent = 10.00
                   WHERE id = ?
                ");
                $u2->bind_param('i', $referrerId);
                $u2->execute();
                $u2->close();
            }

            // d) CREATE subscription record
            $now = date('Y-m-d H:i:s');
            $sub = $conn->prepare("
              INSERT INTO student_subscriptions
                (student_id, plan_id, subscribed_at)
              VALUES (?, ?, ?)
            ");
            $sub->bind_param('iis', $stuId, $plan_id, $now);
            $sub->execute();
            $sub->close();

            // e) CALCULATE FEES (this is where we needed calculate_student_fee())
            $feeData = calculate_student_fee($conn, $stuId, $plan_id, true, false);

            // f) INSERT initial payment as “Pending”
            $pay = $conn->prepare("
              INSERT INTO payments
                (student_id, status, amount_paid, amount_due, paid_at)
              VALUES (?, 'Pending', 0.00, ?, NULL)
            ");
            $amountDue = $feeData['total'];
            $pay->bind_param('id', $stuId, $amountDue);
            $pay->execute();
            $pay->close();

            $conn->commit();
            $_SESSION['flash_success'] = 'Student created successfully.';
            header('Location: index.php?page=students');
            exit;
        }
        catch (\Throwable $e) {
            $conn->rollback();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}

// ─── 3) GENERATE A NEW CSRF TOKEN ───────────────────────────────────
$csrf = generate_csrf_token();

// ─── 4) PRE-FILL FORM VALUES (POST wins over empty) ─────────────────
$form = [
    'email'       => htmlspecialchars($_POST['email']      ?? '', ENT_QUOTES),
    'name'        => htmlspecialchars($_POST['name']       ?? '', ENT_QUOTES),
    'phone'       => htmlspecialchars($_POST['phone']      ?? '', ENT_QUOTES),
    'centre_id'   => (int) ($_POST['centre_id'] ?? 0),
    'group_name'  => htmlspecialchars($_POST['group_name'] ?? '', ENT_QUOTES),
    'plan_id'     => (int) ($_POST['plan_id'] ?? 0),
    'dob'         => htmlspecialchars($_POST['dob']        ?? '', ENT_QUOTES),
    'address'     => htmlspecialchars($_POST['address']    ?? '', ENT_QUOTES),
    'skip_enroll' => ! empty($_POST['skip_enroll_fee'])  ? 1 : 0,
    'skip_adv'    => ! empty($_POST['skip_advance_fee']) ? 1 : 0,
    'referrer'    => htmlspecialchars($_POST['referrer']  ?? '', ENT_QUOTES),
];

?>
<!-- ─── BEGIN: “Add New Student” FRAGMENT ───────────────────────────── -->
<div class="max-w-4xl mx-auto bg-white p-6 rounded-lg shadow">
  <h2 class="text-2xl font-bold text-gray-800 mb-6">➕ Add New Student</h2>

  <?php if ($errors): ?>
    <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
      <ul class="list-disc pl-5">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="form_stage"  value="submit">

    <!-- Email & Password -->
    <div class="col-span-2">
      <label class="block text-gray-700 mb-1">Email (Username)</label>
      <input
        type="email"
        name="email"
        required
        value="<?= $form['email'] ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
    </div>
    <div class="col-span-2">
      <label class="block text-gray-700 mb-1">Password</label>
      <input
        type="password"
        name="password"
        required
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
    </div>

    <!-- Full Name & Phone -->
    <div>
      <label class="block text-gray-700 mb-1">Full Name</label>
      <input
        type="text"
        name="name"
        required
        value="<?= $form['name'] ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
    </div>
    <div>
      <label class="block text-gray-700 mb-1">Phone</label>
      <input
        type="text"
        name="phone"
        value="<?= $form['phone'] ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
    </div>

    <!-- Centre, Group & Plan (cascading selects) -->
    <div>
      <label class="block text-gray-700 mb-1">Centre</label>
      <select
        id="centre"
        name="centre_id"
        required
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
        <option value="">— Select Centre —</option>
        <?php foreach($centres as $c): ?>
          <option
            value="<?= (int)$c['id'] ?>"
            <?= $form['centre_id'] === (int)$c['id'] ? 'selected' : '' ?>
          >
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Group</label>
      <select
        id="group"
        name="group_name"
        required
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
        <option value="">— Select Group —</option>
        <!-- JS populates this based on Centre -->
      </select>
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Plan</label>
      <select
        id="plan"
        name="plan_id"
        required
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
        <option value="">— Select Plan —</option>
        <!-- JS populates this based on Group + Centre -->
      </select>
    </div>

    <!-- Date of Birth & Address -->
    <div>
      <label class="block text-gray-700 mb-1">Date of Birth</label>
      <input
        type="date"
        name="dob"
        value="<?= $form['dob'] ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
    </div>
    <div>
      <label class="block text-gray-700 mb-1">Address</label>
      <input
        type="text"
        name="address"
        value="<?= $form['address'] ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
    </div>

    <!-- Skip Fees Checkboxes -->
    <div class="flex items-center">
      <input
        type="checkbox"
        id="skipEnroll"
        name="skip_enroll_fee"
        value="1"
        <?= $form['skip_enroll'] ? 'checked' : '' ?>
        class="mr-2"
      >
      <label for="skipEnroll" class="text-gray-700">Skip Enrollment Fee (already paid)</label>
    </div>
    <div class="flex items-center">
      <input
        type="checkbox"
        id="skipAdvance"
        name="skip_advance_fee"
        value="1"
        <?= $form['skip_adv'] ? 'checked' : '' ?>
        class="mr-2"
      >
      <label for="skipAdvance" class="text-gray-700">Skip Advance Fee (already paid)</label>
    </div>

    <!-- Referrer Email (optional) -->
    <div class="col-span-2">
      <label class="block text-gray-700 mb-1">Referrer Email (optional)</label>
      <input
        type="email"
        name="referrer"
        value="<?= $form['referrer'] ?>"
        class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
      >
      <p class="text-sm text-gray-500 mt-1">
        If referred, enter the referrer’s email. A 10% discount will apply if valid.
      </p>
    </div>

    <!-- Submit / Cancel -->
    <div class="col-span-2 text-right">
      <button 
        type="submit"
        class="bg-admin-primary text-white px-6 py-2 rounded hover:bg-opacity-90 transition"
      >
        Create Student
      </button>
      <a 
        href="index.php?page=students"
        class="ml-4 text-gray-700 hover:underline"
      >
        Cancel
      </a>
    </div>
  </form>
</div>

<script>
  // ─── JS: Centre → Group → Plan cascading dropdowns ────────────────
  const GROUPS = <?= json_encode($all_groups, JSON_UNESCAPED_UNICODE) ?>;
  const PLANS  = <?= json_encode($all_plans,  JSON_UNESCAPED_UNICODE) ?>;

  const centreEl = document.getElementById('centre');
  const groupEl  = document.getElementById('group');
  const planEl   = document.getElementById('plan');

  function populateGroups() {
    const cid = centreEl.value;
    groupEl.innerHTML = '<option value="">— Select Group —</option>';
    planEl.innerHTML  = '<option value="">— Select Plan —</option>';
    planEl.disabled   = true;

    let matching = GROUPS.filter(x => String(x.centre_id) === String(cid));
    matching.forEach(g => {
      let opt = document.createElement('option');
      opt.value = g.group_name;
      opt.textContent = g.group_name;
      groupEl.appendChild(opt);
    });
    groupEl.disabled = (matching.length === 0);
  }

  function populatePlans() {
    const cid = centreEl.value;
    const grp = groupEl.value;
    planEl.innerHTML = '<option value="">— Select Plan —</option>';

    let matching = PLANS.filter(x => 
      String(x.centre_id) === String(cid) && x.group_name === grp
    );
    matching.forEach(p => {
      let opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = p.label;
      planEl.appendChild(opt);
    });
    planEl.disabled = (matching.length === 0);
  }

  centreEl.addEventListener('change', populateGroups);
  groupEl.addEventListener('change', populatePlans);

  document.addEventListener('DOMContentLoaded', () => {
    if (centreEl.value) {
      populateGroups();
      if (groupEl.value) {
        populatePlans();
      }
    }
  });
</script>
