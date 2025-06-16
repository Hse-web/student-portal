<?php
// File: dashboard/admin/edit_student.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// 1) Identify student
$studentId = (int)($_GET['id'] ?? 0);
if (!$studentId) {
  header('Location:index.php?page=students');
  exit;
}

// 2) Load lookup data
$centres = $conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$plans   = $conn->query("
  SELECT id, centre_id,
         CONCAT(plan_name,' (',duration_months,'m) — ₹',amount) AS label
    FROM payment_plans
   ORDER BY centre_id, duration_months
")->fetch_all(MYSQLI_ASSOC);

// 3) Fetch student & user
$stmt = $conn->prepare("
  SELECT u.id,u.username,s.name,s.phone,s.dob,s.address,s.centre_id,s.is_legacy
    FROM students s
    JOIN users    u ON u.id=s.user_id
   WHERE s.id=?
   LIMIT 1
");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$stmt->bind_result(
  $userId,$existingEmail,
  $existingName,$existingPhone,
  $existingDob,$existingAddress,
  $existingCentre,$existingLegacy
);
if (!$stmt->fetch()) {
  $stmt->close();
  header('Location:index.php?page=students');
  exit;
}
$stmt->close();

// 4) Current plan
$stmt = $conn->prepare("
  SELECT plan_id FROM student_subscriptions
   WHERE student_id=? ORDER BY subscribed_at DESC LIMIT 1
");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$stmt->bind_result($existingPlan);
$stmt->fetch();
$stmt->close();

// 5) Handle POST
$csrf   = generate_csrf_token();
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[]='Session expired.';
  }
  // sanitize
  $email    = trim($_POST['email']      ?? '');
  $name     = trim($_POST['name']       ?? '');
  $phone    = trim($_POST['phone']      ?? '');
  $dob      = $_POST['dob']             ?? '';
  $address  = trim($_POST['address']    ?? '');
  $centre_id= (int)($_POST['centre_id'] ?? 0);
  $plan_id  = (int)($_POST['plan_id']   ?? 0);
  $legacy   = !empty($_POST['is_legacy'])?1:0;

  // validate
  if (!filter_var($email,FILTER_VALIDATE_EMAIL)) $errors[]='Valid email required.';
  if ($name==='')                              $errors[]='Name required.';
  if ($centre_id<1)                            $errors[]='Centre required.';
  if ($plan_id<1)                              $errors[]='Plan required.';

  if (empty($errors)) {
    $conn->begin_transaction();
    try {
      // update user
      $u=$conn->prepare("UPDATE users SET username=?,centre_id=? WHERE id=?");
      $u->bind_param('sii',$email,$centre_id,$userId);
      $u->execute(); $u->close();

      // update student (no group_name)
      $s=$conn->prepare("
        UPDATE students
           SET name=?,phone=?,dob=?,address=?,centre_id=?,is_legacy=?
         WHERE id=?
      ");
      $s->bind_param('ssssiii',$name,$phone,$dob,$address,$centre_id,$legacy,$studentId);
      $s->execute(); $s->close();

      // if plan changed, insert subscription
      if ($plan_id!==$existingPlan) {
        $now=date('Y-m-d H:i:s');
        $i=$conn->prepare("
          INSERT INTO student_subscriptions(student_id,plan_id,subscribed_at)
          VALUES(?,?,?)
        ");
        $i->bind_param('iis',$studentId,$plan_id,$now);
        $i->execute(); $i->close();
      }

      $conn->commit();
      set_flash('Student updated.','success');
      header('Location:index.php?page=students');
      exit;
    } catch(Throwable $e){
      $conn->rollback();
      $errors[]='Error: '.$e->getMessage();
    }
  }
}

// prefill
$form = [
  'email'     => htmlspecialchars($_POST['email']      ?? $existingEmail,    ENT_QUOTES),
  'name'      => htmlspecialchars($_POST['name']       ?? $existingName,     ENT_QUOTES),
  'phone'     => htmlspecialchars($_POST['phone']      ?? $existingPhone,    ENT_QUOTES),
  'dob'       => htmlspecialchars($_POST['dob']        ?? $existingDob,      ENT_QUOTES),
  'address'   => htmlspecialchars($_POST['address']    ?? $existingAddress,  ENT_QUOTES),
  'centre_id' => (int)($_POST['centre_id']   ?? $existingCentre),
  'plan_id'   => (int)($_POST['plan_id']     ?? $existingPlan),
  'legacy'    => !empty($_POST['is_legacy'])?1:$existingLegacy,
];
// current group label
$currentGroup = get_current_group_label($conn,$studentId);
?>
<div class="max-w-4xl mx-auto bg-white p-6 rounded-lg shadow">
  <h2 class="text-2xl font-bold mb-4">Edit Student #<?=$studentId?></h2>

  <?php if($errors): ?>
    <div class="mb-4 p-4 bg-red-100 border-red-400 text-red-700 rounded">
      <ul class="list-disc pl-5">
        <?php foreach($errors as $e):?><li><?=htmlspecialchars($e)?></li><?php endforeach;?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <input type="hidden" name="csrf_token" value="<?=$csrf?>">

    <div>
      <label class="block mb-1">Email</label>
      <input name="email" type="email" required
             class="w-full border rounded px-3 py-2"
             value="<?=$form['email']?>">
    </div>
    <div>
      <label class="block mb-1">Full Name</label>
      <input name="name" type="text" required
             class="w-full border rounded px-3 py-2"
             value="<?=$form['name']?>">
    </div>

    <div>
      <label class="block mb-1">Phone</label>
      <input name="phone" type="text"
             class="w-full border rounded px-3 py-2"
             value="<?=$form['phone']?>">
    </div>
    <div>
      <label class="block mb-1">Initial Stage</label>
      <input type="text" readonly
             class="w-full bg-gray-100 border rounded px-3 py-2"
             value="<?=htmlspecialchars($currentGroup)?>">
      <a href="?page=student_promotions&student_id=<?=$studentId?>"
         class="text-blue-600 hover:underline text-sm mt-1 inline-block">
        Manage Promotions
      </a>
    </div>

    <div>
      <label class="block mb-1">Date of Birth</label>
      <input name="dob" type="date"
             class="w-full border rounded px-3 py-2"
             value="<?=$form['dob']?>">
    </div>
    <div>
      <label class="block mb-1">Address</label>
      <input name="address" type="text"
             class="w-full border rounded px-3 py-2"
             value="<?=$form['address']?>">
    </div>

    <div>
      <label class="block mb-1">Centre</label>
      <select name="centre_id" required
              class="w-full border rounded px-3 py-2">
        <option value="">— Centre —</option>
        <?php foreach($centres as $c):?>
          <option value="<?=$c['id']?>"
            <?=$form['centre_id']===$c['id']?'selected':''?>>
            <?=htmlspecialchars($c['name'])?>
          </option>
        <?php endforeach;?>
      </select>
    </div>
    <div>
      <label class="block mb-1">Plan</label>
      <select name="plan_id" required
              class="w-full border rounded px-3 py-2">
        <option value="">— Plan —</option>
        <?php foreach($plans as $p):?>
          <option value="<?=$p['id']?>"
            data-centre-id="<?=$p['centre_id']?>"
            <?=$form['plan_id']===$p['id']?'selected':''?>>
            <?=htmlspecialchars($p['label'])?>
          </option>
        <?php endforeach;?>
      </select>
    </div>

    <div class="flex items-center">
      <input id="legacy" name="is_legacy" type="checkbox" value="1"
             <?=$form['legacy']?'checked':''?> class="mr-2">
      <label for="legacy">Skip one-time fees</label>
    </div>
    <div></div>

    <div class="col-span-2 text-right space-x-4">
      <button type="submit"
              class="bg-indigo-600 text-white px-6 py-2 rounded">
        Save Changes
      </button>
      <a href="index.php?page=students"
         class="text-gray-700 hover:underline">
        Cancel
      </a>
    </div>
  </form>
</div>
