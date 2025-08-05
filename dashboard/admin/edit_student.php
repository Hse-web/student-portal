<?php
// File: dashboard/admin/edit_student.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../helpers/functions.php';

// 1) Identify student
$studentId = (int)($_GET['id'] ?? 0);
if (!$studentId) {
  header('Location: index.php?page=students');
  exit;
}

// 2) Load lookup data
$centres = $conn
  ->query("SELECT id, name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

// 3) Fetch the student (including their centre and plan)
$stmt = $conn->prepare("
  SELECT 
    u.username        AS email,
    s.name            AS fullname,
    s.phone,
    s.dob,
    s.address,
    s.centre_id,
    s.is_legacy,
    sub.plan_id       AS plan_id
  FROM students s
  JOIN users u ON u.id = s.user_id
  LEFT JOIN (
    SELECT student_id, plan_id
      FROM student_subscriptions
     WHERE (student_id, subscribed_at) IN (
       SELECT student_id, MAX(subscribed_at)
         FROM student_subscriptions
        GROUP BY student_id
     )
  ) sub ON sub.student_id = s.id
  WHERE s.id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result(
  $email,
  $fullname,
  $phone,
  $dob,
  $address,
  $centre_id,
  $is_legacy,
  $existingPlan
);
if (!$stmt->fetch()) {
  header('Location: index.php?page=students');
  exit;
}
$stmt->close();

// 4) Load only plans for that centre
$planStmt = $conn->prepare("
  SELECT id, plan_name, duration_months, amount
    FROM payment_plans
   WHERE centre_id = ?
   ORDER BY duration_months
");
$planStmt->bind_param('i', $centre_id);
$planStmt->execute();
$plans = $planStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$planStmt->close();

// 5) CSRF + flash + errors
$csrf   = generate_csrf_token();
$flash  = get_flash();
$errors = [];

// 6) Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Session expired, please try again.';
  } else {
    // sanitize
    $emailIn     = trim($_POST['email']      ?? '');
    $fullnameIn  = trim($_POST['fullname']   ?? '');
    $phoneIn     = trim($_POST['phone']      ?? '');
    $dobIn       = $_POST['dob']             ?? '';
    $addressIn   = trim($_POST['address']    ?? '');
    $centreIn    = (int)($_POST['centre_id'] ?? 0);
    $planIn      = (int)($_POST['plan_id']   ?? 0);
    $legacyIn    = !empty($_POST['is_legacy']) ? 1 : 0;

    // validate
    if (!filter_var($emailIn, FILTER_VALIDATE_EMAIL)) $errors[]='Valid email required.';
    if ($fullnameIn==='')                             $errors[]='Full name required.';
    if ($centreIn<1)                                  $errors[]='Centre required.';
    if ($planIn<1)                                    $errors[]='Plan required.';

    if (empty($errors)) {
      $conn->begin_transaction();
      try {
        // update users
        $u = $conn->prepare("
          UPDATE users u
             JOIN students s ON s.user_id=u.id
             SET u.username=?, s.centre_id=?
           WHERE s.id=?
        ");
        $u->bind_param('sii', $emailIn, $centreIn, $studentId);
        $u->execute();
        $u->close();

        // update students
        $s = $conn->prepare("
          UPDATE students
             SET name=?, phone=?, dob=?, address=?, centre_id=?, is_legacy=?
           WHERE id=?
        ");
        $s->bind_param(
          'sssiiii',
          $fullnameIn,
          $phoneIn,
          $dobIn,
          $addressIn,
          $centreIn,
          $legacyIn,
          $studentId
        );
        $s->execute();
        $s->close();

        // if plan changed, insert subscription
        if ($planIn !== $existingPlan) {
          $now = date('Y-m-d H:i:s');
          $i = $conn->prepare("
            INSERT INTO student_subscriptions(student_id,plan_id,subscribed_at)
            VALUES(?,?,?)
          ");
          $i->bind_param('iis',$studentId,$planIn,$now);
          $i->execute();
          $i->close();
        }

        $conn->commit();
        set_flash('Student updated successfully.','success');
        header('Location: index.php?page=students');
        exit;

      } catch(Throwable $e) {
        $conn->rollback();
        $errors[] = 'Update failed: '.$e->getMessage();
      }
    }
  }
}

// 7) Prepare form defaults
$form = [
  'email'      => htmlspecialchars($_POST['email']      ?? $email,      ENT_QUOTES),
  'fullname'   => htmlspecialchars($_POST['fullname']   ?? $fullname,   ENT_QUOTES),
  'phone'      => htmlspecialchars($_POST['phone']      ?? $phone,      ENT_QUOTES),
  'dob'        => htmlspecialchars($_POST['dob']        ?? $dob,        ENT_QUOTES),
  'address'    => htmlspecialchars($_POST['address']    ?? $address,    ENT_QUOTES),
  'centre_id'  => (int)      ($_POST['centre_id']   ?? $centre_id),
  'plan_id'    => (int)      ($_POST['plan_id']     ?? $existingPlan),
  'is_legacy'  => !empty($_POST['is_legacy'])?1:$is_legacy,
];
?>
<div class="max-w-4xl mx-auto p-6 bg-white rounded-lg shadow space-y-6">
  <h2 class="text-2xl font-bold">Edit Student #<?= $studentId ?></h2>
  <?php if ($flash): ?>
    <div class="p-3 bg-<?= $flash['type']==='danger'?'red':'green' ?>-100 border-l-4 border-<?= $flash['type']==='danger'?'red':'green' ?>-500">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="p-3 bg-red-100 text-red-700 rounded">
      <ul class="list-disc pl-5">
        <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <!-- Email -->
    <div>
      <label class="block mb-1">Email</label>
      <input name="email" type="email" required class="w-full border rounded px-3 py-2" value="<?= $form['email'] ?>">
    </div>
    <!-- Full Name -->
    <div>
      <label class="block mb-1">Full Name</label>
      <input name="fullname" type="text" required class="w-full border rounded px-3 py-2" value="<?= $form['fullname'] ?>">
    </div>
    <!-- Phone -->
    <div>
      <label class="block mb-1">Phone</label>
      <input name="phone" type="text" class="w-full border rounded px-3 py-2" value="<?= $form['phone'] ?>">
    </div>
    <!-- DOB -->
    <div>
      <label class="block mb-1">Date of Birth</label>
      <input name="dob" type="date" class="w-full border rounded px-3 py-2" value="<?= $form['dob'] ?>">
    </div>
    <!-- Address -->
    <div>
      <label class="block mb-1">Address</label>
      <input name="address" type="text" class="w-full border rounded px-3 py-2" value="<?= $form['address'] ?>">
    </div>
    <!-- Centre -->
    <div>
      <label class="block mb-1">Centre</label>
      <select name="centre_id" required class="w-full border rounded px-3 py-2">
        <option value="">— Select Centre —</option>
        <?php foreach ($centres as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $form['centre_id']===$c['id']?'selected':'' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Plan -->
    <div>
      <label class="block mb-1">Plan</label>
      <select name="plan_id" required class="w-full border rounded px-3 py-2">
        <option value="">— Select Plan —</option>
        <?php foreach($plans as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $form['plan_id']===$p['id']?'selected':'' ?>>
            <?= htmlspecialchars("{$p['plan_name']} ({$p['duration_months']}m) — ₹{$p['amount']}") ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <!-- Skip fees -->
    <div class="flex items-center mt-6">
      <input id="legacy" name="is_legacy" type="checkbox" value="1" class="mr-2" <?= $form['is_legacy']?'checked':'' ?>>
      <label for="legacy">Skip one-time fees</label>
    </div>
    <div></div>
    <!-- Submit -->
    <div class="col-span-2 text-right space-x-4">
      <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded">Save Changes</button>
      <a href="index.php?page=students" class="text-gray-500 hover:underline">Cancel</a>
    </div>
  </form>
</div>
