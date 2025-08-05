<?php
// File: dashboard/student/profile.php

$page = 'profile';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/functions.php';

$studentId = intval($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location:/artovue/login.php');
    exit;
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // — Avatar upload —
    if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = $_FILES['avatar'];
        if ($up['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($up['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif'], true)) {
                $destDir = __DIR__ . '/../../uploads/avatars';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                $fn     = "{$studentId}.{$ext}";
                $target = "$destDir/$fn";
                if (move_uploaded_file($up['tmp_name'], $target)) {
                    $photoPath = "uploads/avatars/$fn";
                    $stmt = $conn->prepare("
                      UPDATE students
                         SET photo_path = ?
                       WHERE id = ?
                    ");
                    $stmt->bind_param('si', $photoPath, $studentId);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'Photo updated successfully.';
                } else {
                    $errors[] = 'Could not save uploaded photo.';
                }
            } else {
                $errors[] = 'Invalid file type. Only JPG, PNG or GIF allowed.';
            }
        } else {
            $errors[] = 'Upload error code: ' . $up['error'];
        }
    }

    // — Profile fields —
    $newPhone   = trim($_POST['phone']   ?? '');
    $newAddress = trim($_POST['address'] ?? '');
    $newDob     = trim($_POST['dob']     ?? '');
    $newGender  = ($_POST['gender'] ?? '') === 'F' ? 'F' : 'M';

    if ($newPhone   === '') $errors[] = 'Phone cannot be blank.';
    if ($newAddress === '') $errors[] = 'Address cannot be blank.';
    if ($newDob     === '') $errors[] = 'Date of birth cannot be blank.';

    if (empty($errors)) {
        $u = $conn->prepare("
          UPDATE students
             SET phone   = ?, 
                 address = ?,
                 dob     = ?,
                 gender  = ?
           WHERE id = ?
        ");
        $u->bind_param('ssssi', $newPhone, $newAddress, $newDob, $newGender, $studentId);
        $u->execute();
        $u->close();
        if (!$success) {
            $success = 'Profile updated successfully.';
        }
    }
}

// — Fetch fresh student data —
$stmt = $conn->prepare("
  SELECT name,
         phone,
         address,
         dob,
         gender,
         centre_id,
         photo_path,
         user_id
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result(
    $name,
    $phone,
    $address,
    $dob,
    $gender,
    $centreId,
    $photoPath,
    $userId
);
$stmt->fetch();
$stmt->close();

// coalesce nulls
$name      = $name      ?? '';
$phone     = $phone     ?? '';
$address   = $address   ?? '';
$dob       = $dob       ?? '';
$gender    = strtoupper($gender ?? 'M');
$photoPath = $photoPath ?? '';

// — Email & Centre lookup —
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$stmt->bind_result($email);
$stmt->fetch();
$stmt->close();
$email = $email ?? '';

$stmt = $conn->prepare("SELECT name FROM centres WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $centreId);
$stmt->execute();
$stmt->bind_result($centreName);
if (!$stmt->fetch()) $centreName = '—';
$stmt->close();

// — Star count —
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) $starCount = 0;
$stmt->close();

// — Group label —
$groupName = get_current_group_label($conn, $studentId);

// — Avatar URL logic —
// Derive “/artovue” (or your base folder) dynamically:
$baseUrl = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'], 3)), '/');

// If user‐uploaded photo exists on disk, use it; otherwise gender‐based placeholder
$fsPath = __DIR__ . '/../../' . $photoPath;
if ($photoPath && file_exists($fsPath)) {
    $avatarUrl = $baseUrl . '/' . ltrim($photoPath, '/');
} else {
    $which     = strtolower($gender === 'F' ? 'female' : 'male');
    $avatarUrl = $baseUrl . "/assets/avatar-{$which}.png";
}
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6b21a8">
<link rel="icon" href="/assets/icons/icon-192.png">
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
      .then(() => console.log('✅ SW registered'))
      .catch(err => console.error('⚠️ SW registration failed:', err));
  }
</script>
<div class="container-fluid px-4 py-6">
  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
    </div>
  <?php elseif ($success): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <div class="row gx-6">
    <!-- LEFT: Avatar & Form -->
    <div class="col-12 col-lg-6">
      <div class="bg-white rounded-xl shadow-lg p-6 space-y-6">
        <div class="text-center">
          <img id="avatarImg"
               src="<?= htmlspecialchars($avatarUrl) ?>"
               class="w-32 h-32 mx-auto rounded-full object-cover">
          <form id="avatarForm" method="post" enctype="multipart/form-data" class="mt-3">
            <input type="file" name="avatar" id="avatarInput" accept="image/*" class="hidden">
            <label for="avatarInput"
                   class="text-blue-600 hover:underline text-sm cursor-pointer">
              Change Photo
            </label>
          </form>
        </div>

        <form method="post" class="space-y-4">
          <!-- Name & Email read-only -->
          <div>
            <label class="block text-gray-700 mb-1">Name</label>
            <input type="text" readonly
                   value="<?= htmlspecialchars($name) ?>"
                   class="w-full bg-gray-100 rounded-md p-2">
          </div>
          <div>
            <label class="block text-gray-700 mb-1">Email</label>
            <input type="email" readonly
                   value="<?= htmlspecialchars($email) ?>"
                   class="w-full bg-gray-100 rounded-md p-2">
          </div>

          <!-- Phone -->
          <div>
            <label class="block text-gray-700 mb-1">Phone</label>
            <input type="text" name="phone"
                   value="<?= htmlspecialchars($phone) ?>"
                   class="w-full border rounded-md p-2"
                   placeholder="Enter your phone">
          </div>

          <!-- Date of Birth -->
          <div>
            <label class="block text-gray-700 mb-1">Date of Birth</label>
            <input type="date" name="dob"
                   value="<?= htmlspecialchars($dob) ?>"
                   class="w-full border rounded-md p-2">
          </div>

          <!-- Gender selector -->
          <div>
            <label class="block text-gray-700 mb-1">Gender</label>
            <select name="gender" class="w-full border rounded-md p-2">
              <option value="M" <?= $gender==='M' ? 'selected':'' ?>>Male</option>
              <option value="F" <?= $gender==='F' ? 'selected':'' ?>>Female</option>
            </select>
          </div>

          <!-- Address -->
          <div>
            <label class="block text-gray-700 mb-1">Address</label>
            <input type="text" name="address"
                   value="<?= htmlspecialchars($address) ?>"
                   class="w-full border rounded-md p-2"
                   placeholder="Enter your address">
          </div>

          <button type="submit"
                  class="w-full py-2 bg-gradient-to-r from-orange-500 to-red-500
                         text-white rounded-lg font-semibold hover:opacity-90 transition">
            Save Changes
          </button>
        </form>
      </div>
    </div>

    <!-- RIGHT: Payments / Class Info / Stars -->
    <div class="col-12 col-lg-6 space-y-6 mt-6 lg:mt-0">
      <!-- Payments -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-semibold mb-4">My Payments</h3>
        <ul class="space-y-2">
          <?php
          $stm = $conn->prepare("
            SELECT payment_id, status, amount_paid, amount_due, paid_at
              FROM payments
             WHERE student_id = ?
             ORDER BY paid_at DESC, payment_id DESC
          ");
          $stm->bind_param('i', $studentId);
          $stm->execute();
          $payments = $stm->get_result()->fetch_all(MYSQLI_ASSOC);
          $stm->close();
          ?>
          <?php if (empty($payments)): ?>
            <li class="text-gray-500">No payment records found.</li>
          <?php else: foreach($payments as $p): ?>
            <li class="flex justify-between items-center">
              <span>
                #<?= htmlspecialchars($p['payment_id']) ?>
                <?php if ($p['paid_at']): ?>
                  <small class="text-gray-400">
                    (<?= date('M j, Y', strtotime($p['paid_at'])) ?>)
                  </small>
                <?php endif; ?>
              </span>
              <span class="inline-block 
                           <?= $p['status']==='Paid'  ? 'bg-green-500'
                             : ($p['status']==='Overdue'? 'bg-red-500'
                             : 'bg-yellow-500') ?>
                           text-white text-xs px-2 py-1 rounded-full">
                <?= htmlspecialchars($p['status']) ?>
              </span>
            </li>
          <?php endforeach; endif; ?>
        </ul>
      </div>

      <!-- Class Info -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-semibold mb-4">My Class Info</h3>
        <div class="space-y-2">
          <div class="flex justify-between"><span>Group</span><span><?= htmlspecialchars($groupName) ?></span></div>
          <div class="flex justify-between"><span>Center</span><span><?= htmlspecialchars($centreName) ?></span></div>
        </div>
      </div>

      <!-- Stars -->
      <div class="bg-white rounded-xl shadow-lg p-6 flex justify-between items-center">
        <h3 class="text-lg font-semibold">My Stars</h3>
        <span class="text-3xl font-bold text-orange-500"><?= $starCount ?></span>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('avatarInput').addEventListener('change', e => {
  const file = e.target.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    document.getElementById('avatarImg').src = reader.result;
    document.getElementById('avatarForm').submit();
  };
  reader.readAsDataURL(file);
});
</script>
