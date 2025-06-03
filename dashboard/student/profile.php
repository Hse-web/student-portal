<?php
// File: dashboard/student/profile.php
$page = 'profile';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = $_SESSION['student_id'];
$userId    = $_SESSION['user_id'];

// ─── Handle “update profile” POST ───────────────────────────────────
$errors  = [];
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (! verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Session expired—please reload and try again.';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name === '') {
            $errors[] = 'Name cannot be empty.';
        }

        if (empty($errors)) {
            // Update students table
            $stmt = $conn->prepare("
              UPDATE students
                 SET name = ?, phone = ?, address = ?
               WHERE id = ?
            ");
            $stmt->bind_param('sssi', $name, $phone, $address, $studentId);
            $stmt->execute();
            $stmt->close();

            // Optionally, update the users.username if you let them change email
            // (In this example, we do not allow changing email via profile page.)

            $success = 'Profile updated successfully.';
        }
    }
}

// ─── Fetch the existing student record ──────────────────────────────
$stmt2 = $conn->prepare("
  SELECT s.name, s.phone, s.address, u.username AS email
    FROM students s
    JOIN users    u ON u.id = s.user_id
   WHERE s.id = ?
   LIMIT 1
");
$stmt2->bind_param('i', $studentId);
$stmt2->execute();
$stmt2->bind_result($existingName, $existingPhone, $existingAddress, $existingEmail);
$stmt2->fetch();
$stmt2->close();

// Pre-fill form values (POST takes precedence)
$form = [
  'name'    => htmlspecialchars($_POST['name'] ?? $existingName),
  'phone'   => htmlspecialchars($_POST['phone'] ?? $existingPhone),
  'address' => htmlspecialchars($_POST['address'] ?? $existingAddress),
  'email'   => htmlspecialchars($existingEmail), // email is not editable
];

// CSRF for the form
$csrf = generate_csrf_token();
?>
  <div class="w-full max-w-3xl mx-auto">
    <h2 class="text-2xl font-semibold text-gray-800 mb-4">My Profile</h2>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php elseif ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

      <div class="col-md-6">
        <label class="form-label">Name</label>
        <input 
          type="text" 
          name="name" 
          class="form-control" 
          value="<?= $form['name'] ?>" 
          required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Email (cannot change)</label>
        <input 
          type="email" 
          class="form-control" 
          value="<?= $form['email'] ?>" 
          readonly>
      </div>

      <div class="col-md-6">
        <label class="form-label">Phone</label>
        <input 
          type="text" 
          name="phone" 
          class="form-control" 
          value="<?= $form['phone'] ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label">Address</label>
        <input 
          type="text" 
          name="address" 
          class="form-control" 
          value="<?= $form['address'] ?>">
      </div>

      <div class="col-12 text-end">
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
<?php
