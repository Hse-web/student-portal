<?php
// File: dashboard/student/upload_payment_proof.php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fee_calculator.php';

$student_id = (int)($_SESSION['student_id'] ?? 0);
if (!$student_id) {
    header('Location:/artovue/login.php');
    exit;
}

// ─── POST: handle upload ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    // 1) Recompute dues
    list($totalDue, $nextDueLabel, $nextDueISO) = compute_student_due($conn, $student_id);
    if ($totalDue <= 0) {
        $_SESSION['flash_error'] = 'You have no outstanding dues.';
        header('Location: /artovue/dashboard/student/?page=student_payment');
        exit;
    }

    // 2) Validate uploaded file
    if (empty($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = 'Please select a file to upload.';
        header('Location: upload_payment_proof.php');
        exit;
    }
    $f   = $_FILES['proof_file'];
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (! in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
        $_SESSION['flash_error'] = 'Only JPG/PNG/PDF ≤ 5 MB allowed.';
        header('Location: upload_payment_proof.php');
        exit;
    }
    if ($f['size'] > 5*1024*1024) {
        $_SESSION['flash_error'] = 'File too large.';
        header('Location: upload_payment_proof.php');
        exit;
    }

    // 3) Move the file
    $basename = uniqid("proof_{$student_id}_") . ".$ext";
    $destDir  = __DIR__ . '/../../uploads/payment_proofs/';
    if (! is_dir($destDir)) mkdir($destDir, 0755, true);
    $destPath = $destDir . $basename;
    if (! move_uploaded_file($f['tmp_name'], $destPath)) {
        $_SESSION['flash_error'] = 'Failed to save file.';
        header('Location: upload_payment_proof.php');
        exit;
    }

    // 4) FIRST check if payment row exists for THIS STUDENT & DUE DATE (most important fix)
    $stmt = $conn->prepare("
      SELECT payment_id 
        FROM payments
       WHERE student_id=? 
         AND due_date=?
       LIMIT 1
    ");
    $stmt->bind_param('is', $student_id, $nextDueISO);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (! empty($res['payment_id'])) {
        $payment_id = (int)$res['payment_id'];
    } else {
        // insert new Pending payment ONLY if no existing payment row
        $ins = "
          INSERT INTO payments
            (student_id, status, amount_due, due_date)
          VALUES (?, 'Pending', ?, ?)
        ";
        $stmt = $conn->prepare($ins);
        $stmt->bind_param('ids', $student_id, $totalDue, $nextDueISO);
        $stmt->execute();
        $payment_id = $conn->insert_id;
        $stmt->close();
    }

    // 5) Insert the proof referencing that payment_id
    $proofSql = "
      INSERT INTO payment_proofs
        (student_id, payment_id, file_path, status, payment_method, txn_id, amount, uploaded_at)
      VALUES (?, ?, ?, 'Pending', ?, ?, ?, NOW())
    ";
    $stmt = $conn->prepare($proofSql);
    $method = $_POST['payment_method'] ?? 'Other';
    $txn    = $_POST['txn_id'] ?: null;
    $stmt->bind_param(
      'iisssd',
      $student_id,
      $payment_id,
      $basename,
      $method,
      $txn,
      $totalDue
    );
    if ($stmt->execute()) {
        $_SESSION['flash_success'] = '✓ Proof uploaded – awaiting approval.';
    } else {
        $_SESSION['flash_error'] = 'Database error while saving proof.';
    }
    $stmt->close();

    header('Location: student_payment.php');
    exit;
}

// ─── GET: show the upload form ───────────────────────────────────────────
list($totalDue, $nextDueLabel, $nextDueISO) = compute_student_due($conn, $student_id);

// fetch student name
$stmt = $conn->prepare("SELECT name FROM students WHERE id=?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($stuName);
$stmt->fetch();
$stmt->close();

// CSRF token for form
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload Payment Proof</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2 class="mb-4">📤 Upload Payment Proof</h2>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert alert-success"><?= $_SESSION['flash_success'] ?></div>
      <?php unset($_SESSION['flash_success']); endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert alert-danger"><?= $_SESSION['flash_error'] ?></div>
      <?php unset($_SESSION['flash_error']); endif; ?>

    <div class="card mx-auto" style="max-width:600px">
      <div class="card-body">
        <?php if ($totalDue <= 0): ?>
          <div class="alert alert-info">You have no outstanding dues.</div>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

            <div class="mb-3">
              <label class="form-label">Student</label>
              <input type="text" class="form-control" disabled
                     value="<?= htmlspecialchars($stuName) ?>">
            </div>

            <div class="mb-3">
              <label class="form-label">Amount Owed (₹)</label>
              <input type="text" class="form-control" disabled
                     value="<?= number_format($totalDue,2) ?>">
              <small class="text-muted">Next Due: <?= htmlspecialchars($nextDueLabel) ?></small>
            </div>

            <div class="mb-3">
              <label class="form-label">Payment Method</label>
              <select name="payment_method" class="form-select" required>
                <option value="">— Select —</option>
                <option>UPI</option>
                <option>BankTransfer</option>
                <option>Cash</option>
                <option>Other</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Transaction ID (optional)</label>
              <input type="text" name="txn_id" class="form-control">
            </div>

            <div class="mb-3">
              <label class="form-label">Proof (JPG/PNG/PDF ≤5 MB)</label>
              <input type="file" name="proof_file" class="form-control"
                     accept=".jpg,.jpeg,.png,.pdf" required>
            </div>

            <button type="submit" class="btn btn-primary">
              <i class="bi bi-upload"></i> Submit Proof
            </button>
            <a href="student_payment.php" class="btn btn-secondary ms-2">Cancel</a>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
