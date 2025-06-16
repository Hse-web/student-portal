<?php
// File: dashboard/student/upload_payment_proof.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// ─── Add this line so compute_student_due() is defined ───────────────
require_once __DIR__ . '/../includes/functions.php';

require_once __DIR__ . '/../includes/fee_calculator.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}
// ─── Fetch current due amount to prefill the form ───────────────
list($totalDue, $nextDueDate) = compute_student_due($conn, $studentId);

// ─── Fetch student info (for display) ───────────────────────────
$stmt = $conn->prepare("SELECT name, email FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($stuName, $stuEmail);
$stmt->fetch();
$stmt->close();

// ─── Generate CSRF token ─────────────────────────────────────────
$csrf = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload Payment Proof</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Bootstrap 5 CSS -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2 class="mb-4">📤 Upload Payment Proof</h2>
    <div class="card mx-auto w-75">
      <div class="card-body">
        <form
          method="post"
          action="../../actions/upload_proof.php"
          enctype="multipart/form-data"
        >
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="student_id" value="<?= $studentId ?>">
          <input type="hidden" name="amount"     value="<?= round($totalDue) ?>">

          <!-- Student Name (read-only) -->
          <div class="mb-3">
            <label class="form-label">Student</label>
            <input
              type="text"
              class="form-control"
              value="<?= htmlspecialchars($stuName) ?>"
              disabled
            >
          </div>

          <!-- Amount Owed (read-only) -->
          <div class="mb-3">
            <label class="form-label">Amount Owed (₹)</label>
            <input
              type="text"
              class="form-control"
              value="<?= round($totalDue) ?>"
              disabled
            >
          </div>

          <!-- Payment Method -->
          <div class="mb-3">
            <label class="form-label">Payment Method</label>
            <select
              name="payment_method"
              class="form-select"
              required
            >
              <option value="">— Select Method —</option>
              <option value="UPI">UPI</option>
              <option value="BankTransfer">Bank Transfer</option>
              <option value="Cash">Cash</option>
              <option value="Other">Other</option>
            </select>
          </div>

          <!-- Transaction ID (if any) -->
          <div class="mb-3">
            <label class="form-label">Transaction ID (if any)</label>
            <input
              type="text"
              name="txn_id"
              class="form-control"
              placeholder="Optional"
            >
          </div>

          <!-- File Upload -->
          <div class="mb-3">
            <label class="form-label">Upload Proof (JPEG/PNG/PDF ≤ 5MB)</label>
            <input
              type="file"
              name="proof_file"
              class="form-control"
              accept=".jpg,.jpeg,.png,.pdf"
              required
            >
          </div>

          <button type="submit" class="btn btn-primary">
            <i class="bi bi-upload"></i> Submit Proof
          </button>
          <a href="student_payment.php" class="btn btn-secondary ms-2">
            Cancel
          </a>
        </form>
      </div>
    </div>
  </div>

  <!-- Bootstrap 5 JS -->
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
  ></script>
</body>
</html>
