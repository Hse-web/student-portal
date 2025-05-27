<?php
// File: dashboard/student/upload_payment_proof.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

// Generate & store a CSRF token
$token = bin2hex(random_bytes(16));
$_SESSION['csrf_token'] = $token;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Upload Payment Proof</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" 
    rel="stylesheet"
  >
</head>
<body class="bg-light">
  <div class="container py-5" style="max-width:560px">
    <h3 class="text-center mb-4">Upload Payment Proof</h3>
    <form action="../../actions/upload_proof.php"
          method="POST"
          enctype="multipart/form-data"
          class="card p-4 shadow-sm">
      <!-- CSRF token field must match the handler’s check -->
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($token)?>">
      <div class="mb-3">
        <label class="form-label">Screenshot / PDF (max 5 MB)</label>
        <input type="file"
               name="proof_file"
               accept=".jpg,.jpeg,.png,.pdf"
               required
               class="form-control">
      </div>
      <div class="mb-3">
        <label class="form-label">Payment Method</label>
        <select name="payment_method"
                class="form-select"
                required>
          <option disabled selected value="">Choose…</option>
          <option>UPI</option>
          <option>BankTransfer</option>
          <option>Cash</option>
          <option>Other</option>
        </select>
      </div>
      <div class="mb-4">
        <label class="form-label">Transaction ID / Ref No.</label>
        <input type="text"
               name="txn_id"
               class="form-control"
               required>
      </div>
      <button type="submit"
              class="btn btn-primary w-100">
        Submit Proof
      </button>
    </form>
  </div>
</body>
</html>
