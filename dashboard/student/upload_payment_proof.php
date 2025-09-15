<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';

$studentId = intval($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: /artovue/login.php');
    exit;
}

$feeData = compute_student_due($conn, $studentId);
$student = fetch_student_info($conn, $studentId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload Payment Proof</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="card shadow-sm mx-auto" style="max-width: 720px;">
      <div class="card-header bg-primary text-white">
        <h4 class="mb-0">Upload Payment Proof</h4>
      </div>
      <div class="card-body">
        <?php if (!empty($feeData['due_date'])): ?>
          <p><strong>Due Date:</strong> <?= htmlspecialchars(date('M j, Y', strtotime($feeData['due_date']))) ?></p>
        <?php endif; ?>

        <p><strong>Total Amount:</strong> ₹<?= number_format((float)($feeData['total'] ?? 0), 2) ?> <span class="text-muted">(incl. GST)</span></p>

        <form action="/artovue/actions/upload_proof.php" method="POST" enctype="multipart/form-data">
          <input type="hidden" name="payment_id" value="<?= (int)($feeData['payment_id'] ?? 0) ?>">

          <div class="mb-3">
            <label class="form-label">Student Name</label>
            <input type="text" value="<?= htmlspecialchars($student['name']) ?>" readonly class="form-control-plaintext">
          </div>

          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select name="payment_method" class="form-select" required>
              <option value="">Select Method</option>
              <option value="UPI">UPI</option>
              <option value="BankTransfer">Bank Transfer</option>
              <option value="Cash">Cash</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Transaction ID <small class="text-muted">(Optional)</small></label>
            <input type="text" name="txn_id" class="form-control" placeholder="UPI Ref / Bank UTR / Receipt no.">
          </div>

          <div class="mb-3">
            <label class="form-label">Upload Proof</label>
            <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
            <small class="text-muted">Accepted: jpg, png, pdf · Max size: 5MB</small>
          </div>

          <div class="d-flex gap-3">
            <button type="submit" class="btn btn-primary">Upload</button>
            <a href="student_payment.php" class="btn btn-outline-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
