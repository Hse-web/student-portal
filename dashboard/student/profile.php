<?php
// File: dashboard/student/profile.php

$page = 'profile';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

require_once __DIR__ . '/../includes/functions.php';

$studentId = intval($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location:/artovue/login.php');
    exit;
}

$errors  = [];
$success = '';

// ─────────────────────────────────────────────────────────────────────────────
// POST: avatar + profile fields
// ─────────────────────────────────────────────────────────────────────────────
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
        if (!$success) $success = 'Profile updated successfully.';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fetch student + related display info
// ─────────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
  SELECT name, phone, address, dob, gender, centre_id, photo_path, user_id
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($name, $phone, $address, $dob, $gender, $centreId, $photoPath, $userId);
$stmt->fetch();
$stmt->close();

$name      = $name      ?? '';
$phone     = $phone     ?? '';
$address   = $address   ?? '';
$dob       = $dob       ?? '';
$gender    = strtoupper($gender ?? 'M');
$photoPath = $photoPath ?? '';

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

$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) $starCount = 0;
$stmt->close();

$groupName = get_current_group_label($conn, $studentId);

// Avatar URL
$baseUrl = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'], 3)), '/');
$fsPath  = __DIR__ . '/../../' . $photoPath;
if ($photoPath && file_exists($fsPath)) {
    $avatarUrl = $baseUrl . '/' . ltrim($photoPath, '/');
} else {
    $which     = strtolower($gender === 'F' ? 'female' : 'male');
    $avatarUrl = $baseUrl . "/assets/avatar-{$which}.png";
}

// ─────────────────────────────────────────────────────────────────────────────
// My Payments widget — show last 3 UNIQUE cycles (de-duped by anchor date)
// anchor_date preference: due_date → latest approved_at → paid_at → created_at
// ─────────────────────────────────────────────────────────────────────────────
$MAX_ROWS = 3;

$q = $conn->prepare("
    SELECT 
        p.payment_id,
        p.amount_paid,
        p.amount_due,
        p.due_date,
        p.paid_at,
        pp.file_path,
        pp.status as proof_status
    FROM payments p
    LEFT JOIN payment_proofs pp ON p.payment_id = pp.payment_id
    WHERE p.student_id = ?
    ORDER BY p.paid_at DESC
    LIMIT 5
");
$q->bind_param('i', $studentId);
$q->execute();
$res  = $q->get_result();
$raw  = $res->fetch_all(MYSQLI_ASSOC);
$q->close();

// De-duplicate by cycle anchor date (string 'YYYY-MM-DD')
$seen = [];
$payments = [];
foreach ($raw as $row) {
    $k = $row['anchor_date'] ?: '0000-00-00';
    if (isset($seen[$k])) continue;
    $seen[$k] = true;
    $payments[] = $row;
    if (count($payments) >= $MAX_ROWS) break;
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
               class="w-32 h-32 mx-auto rounded-full object-cover"
               alt="Avatar">
          <form id="avatarForm" method="post" enctype="multipart/form-data" class="mt-3">
            <input type="file" name="avatar" id="avatarInput" accept="image/*" class="hidden">
            <label for="avatarInput"
                   class="text-blue-600 hover:underline text-sm cursor-pointer">
              Change Photo
            </label>
          </form>
        </div>

        <form method="post" class="space-y-4">
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
          <div>
            <label class="block text-gray-700 mb-1">Phone</label>
            <input type="text" name="phone"
                   value="<?= htmlspecialchars($phone) ?>"
                   class="w-full border rounded-md p-2"
                   placeholder="Enter your phone">
          </div>
          <div>
            <label class="block text-gray-700 mb-1">Date of Birth</label>
            <input type="date" name="dob"
                   value="<?= htmlspecialchars($dob) ?>"
                   class="w-full border rounded-md p-2">
          </div>
          <div>
            <label class="block text-gray-700 mb-1">Gender</label>
            <select name="gender" class="w-full border rounded-md p-2">
              <option value="M" <?= $gender==='M' ? 'selected':'' ?>>Male</option>
              <option value="F" <?= $gender==='F' ? 'selected':'' ?>>Female</option>
            </select>
          </div>
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

        <ul class="list-group">
          <?php if (!$payments): ?>
            <li class="list-group-item text-muted">No payments yet.</li>
          <?php else: foreach ($payments as $p): 
            $dateStr = $p['anchor_date'] ? date('M j, Y', strtotime($p['anchor_date'])) : '—';
            $badge = ($p['status']==='Paid')
              ? 'bg-success'
              : (($p['status']==='Overdue') ? 'bg-danger' : 'bg-warning text-dark');
          ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <span>
                #<?= (int)$p['payment_id'] ?>
                <small class="text-muted">(<?= htmlspecialchars($dateStr) ?>)</small>
              </span>
              <span class="badge <?= $badge ?>">
                <?= htmlspecialchars($p['status']) ?>
              </span>
            </li>
          <?php endforeach; endif; ?>
        </ul>
        <small class="text-muted d-block mt-2">
          Showing the most recent unique billing cycles.
        </small>
      </div>

      <!-- Class Info -->
      <div class="bg-white rounded-xl shadow-lg p-6">
        <h3 class="text-lg font-semibold mb-4">My Class Info</h3>
        <div class="space-y-2">
          <div class="d-flex justify-content-between">
            <span>Group</span><span><?= htmlspecialchars($groupName) ?></span>
          </div>
          <div class="d-flex justify-content-between">
            <span>Center</span><span><?= htmlspecialchars($centreName) ?></span>
          </div>
        </div>
      </div>

      <!-- Stars -->
      <div class="bg-white rounded-xl shadow-lg p-6 d-flex justify-content-between align-items-center">
        <h3 class="text-lg font-semibold mb-0">My Stars</h3>
        <span class="fs-2 fw-bold text-warning"><?= (int)$starCount ?></span>
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
