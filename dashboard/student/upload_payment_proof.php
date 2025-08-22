<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) { header('Location: ../../login.php'); exit; }

list($totalDue,) = compute_student_due($conn, $studentId);

$stmt = $conn->prepare("SELECT name, email FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($stuName, $stuEmail);
$stmt->fetch(); $stmt->close();

$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload Payment Proof</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2 class="mb-4">Upload Payment Proof</h2>
    <div class="card mx-auto" style="max-width: 720px;">
      <div class="card-body">
        <form method="post" action="../../actions/upload_proof.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <div class="mb-3">
            <label class="form-label">Student</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($stuName) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount Due (₹)</label>
            <input type="text" class="form-control" value="<?= number_format($totalDue,2) ?>" disabled>
          </div>
          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select name="payment_method" class="form-select" required>
              <option value="">— Select Method —</option>
              <option value="UPI">UPI</option>
              <option value="BankTransfer">Bank Transfer</option>
              <option value="Cash">Cash</option>
              <option value="Other">Other</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Transaction ID (optional)</label>
            <input type="text" name="txn_id" class="form-control" placeholder="UPI Ref / Bank UTR / Receipt no.">
          </div>
          <div class="mb-3">
            <label class="form-label">Upload Proof (JPEG/PNG/PDF ≤ 5MB)</label>
            <input type="file" name="proof_file" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
          </div>
          <button type="submit" class="btn btn-primary">Submit Proof</button>
          <a href="student_payment.php" class="btn btn-secondary ms-2">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
