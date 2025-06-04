<?php
// File: dashboard/admin/edit_student.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// 1) Which student are we editing?
$studentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($studentId < 1) {
    header('Location: index.php?page=students');
    exit;
}

// 2) Load “centre” dropdown & “plan” dropdown data
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

// 3) Fetch the existing student (and linked user_id)
$stmt = $conn->prepare("
  SELECT 
    u.id       AS user_id,
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
    $stmt->close();
    header('Location: index.php?page=students');
    exit;
}
$stmt->close();

// 4) Find current plan for that student
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
$csrf = generate_csrf_token();
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
      // a) Update users table (username + centre_id)
      $u = $conn->prepare("
        UPDATE users
           SET username = ?, centre_id = ?
         WHERE id = ?
      ");
      $u->bind_param('sii', $email, $centreId, $userId);
      $u->execute();
      $u->close();

      // b) Update students table
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

      // (Optional) Audit‐log here if you need:
      // log_audit($conn, $_SESSION['user_id'], 'UPDATE', 'students', $studentId, [
      //   'name'       => ['old' => $existingName, 'new' => $name],
      //   'email'      => ['old' => $existingEmail, 'new' => $email],
      //   'group_name' => ['old' => $existingGroup, 'new' => $groupName],
      // ]);

      // c) If the plan changed, insert a new subscription
      if ($planId !== $existingPlan) {
        $now = date('Y-m-d H:i:s');
        $ins = $conn->prepare("
          INSERT INTO student_subscriptions
            (student_id, plan_id, subscribed_at)
          VALUES (?, ?, ?)
        ");
        $ins->bind_param('iis', $studentId, $planId, $now);
        $ins->execute();
        $ins->close();

        // You might also recompute fees/payments here if needed.
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

// 6) Pre‐fill form values (POST takes precedence over existing)
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
<div class="max-w-4xl mx-auto bg-white p-6 rounded-lg shadow">
  <h2 class="text-2xl font-bold mb-4">Edit Student #<?= $studentId ?></h2>

  <?php if ($errors): ?>
    <div class="bg-red-100 text-red-800 p-4 rounded mb-4">
      <ul class="list-disc list-inside">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div>
      <label class="block text-gray-700 mb-1">Email</label>
      <input name="email" type="email" required
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= $form['email'] ?>">
    </div>
    <div>
      <label class="block text-gray-700 mb-1">Full Name</label>
      <input name="name" type="text" required
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= $form['name'] ?>">
    </div>
    <div>
      <label class="block text-gray-700 mb-1">Phone</label>
      <input name="phone" type="text"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= $form['phone'] ?>">
    </div>
    <div>
      <label class="block text-gray-700 mb-1">Group</label>
      <input name="group_name" type="text" required
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= $form['group_name'] ?>">
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Centre</label>
      <select name="centre_id" required
              class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary">
        <option value="">— Select Centre —</option>
        <?php foreach ($centres as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= $form['centre_id'] === $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Plan</label>
      <select name="plan_id" required
              class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary">
        <option value="">— Select Plan —</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= $p['id'] ?>"
            data-centre-id="<?= $p['centre_id'] ?>"
            <?= $form['plan_id'] === $p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['plan_name']) ?> — <?= $p['duration_months'] ?>m / ₹<?= number_format($p['amount'],2) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex items-center">
      <input
        id="is_legacy"
        name="is_legacy"
        type="checkbox"
        value="1"
        class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
        <?= $form['is_legacy'] ? 'checked' : '' ?>
      >
      <label for="is_legacy" class="ml-2 block text-gray-700">
        Skip one‐time fees (already paid)
      </label>
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Date of Birth</label>
      <input name="dob" type="date"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= $form['dob'] ?>">
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Address</label>
      <input name="address" type="text"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary"
             value="<?= $form['address'] ?>">
    </div>

    <div class="col-span-2 text-right">
      <button class="px-6 py-2 bg-admin-primary text-white rounded-lg hover:opacity-90 transition">
        Save Changes
      </button>
      <a href="index.php?page=students" class="ml-4 px-4 py-2 bg-gray-300 text-gray-800 rounded-lg hover:bg-gray-400 transition">
        Cancel
      </a>
    </div>
  </form>
</div>

<script>
  // If you want to dynamically filter “Plan” by “Centre”, you can add JS similar to add_student.
  document.querySelector('select[name=centre_id]').addEventListener('change', e => {
    const cid = e.target.value;
    document.querySelectorAll('select[name=plan_id] option').forEach(opt => {
      // show only those with matching data-centre-id, hide others
      opt.style.display = opt.getAttribute('data-centre-id') === cid ? '' : 'none';
    });
  });
</script>
