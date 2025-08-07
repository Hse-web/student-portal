<?php
// File: dashboard/student/student_payment.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/fee_calculator.php';

// Helpers
function formatDate($iso) {
    $dt = new DateTime($iso, new DateTimeZone('Asia/Kolkata'));
    return $dt->format('M j, Y');
}

function getOrCreatePayment($conn, $student_id, $due_date, $amount_due) {
    $stmt = $conn->prepare("
        SELECT payment_id 
          FROM payments 
         WHERE student_id=? AND due_date=?
         LIMIT 1
    ");
    $stmt->bind_param('is', $student_id, $due_date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) return $row['payment_id'];
    $ins = $conn->prepare("
        INSERT INTO payments(student_id,status,amount_due,due_date)
        VALUES(?, 'Pending', ?, ?)
    ");
    $ins->bind_param('ids', $student_id, $amount_due, $due_date);
    $ins->execute();
    $pid = $ins->insert_id;
    $ins->close();
    return $pid;
}

function getLatestProof($conn, $payment_id) {
    $stmt = $conn->prepare("
        SELECT status, rejection_reason, uploaded_at
          FROM payment_proofs
         WHERE payment_id=?
         ORDER BY uploaded_at DESC
         LIMIT 1
    ");
    $stmt->bind_param('i', $payment_id);
    $stmt->execute();
    $proof = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $proof ?: null;
}

// 1) Subscription
$student_id = (int)($_SESSION['student_id'] ?? 0);
if (!$student_id) { header('Location:/artovue/login.php'); exit; }
$stmt = $conn->prepare("
  SELECT ss.subscribed_at, ss.plan_id, p.plan_name, p.duration_months
    FROM student_subscriptions ss
    JOIN payment_plans p ON p.id = ss.plan_id
   WHERE ss.student_id = ?
   ORDER BY ss.subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$sub) { echo '<p class="text-danger">No active subscription found.</p>'; exit; }

// 2) Next due date
$tz = new DateTimeZone('Asia/Kolkata');
$stmt = $conn->prepare("
  SELECT due_date
    FROM payments
   WHERE student_id=? AND status='Paid' AND due_date<>'0000-00-00'
   ORDER BY due_date DESC
   LIMIT 1
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$lastPaid = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($lastPaid) {
    $dueDt = new DateTime($lastPaid['due_date'], $tz);
} else {
    $dueDt = new DateTime($sub['subscribed_at'], $tz);
}
$dueDt->modify('+' . $sub['duration_months'] . ' months')
      ->setDate((int)$dueDt->format('Y'), (int)$dueDt->format('m'), 5);
$nextDueISO   = $dueDt->format('Y-m-d');
$nextDueLabel = $dueDt->format('M j, Y');

// 3) Fees
$fees    = calculate_student_fee($conn, $student_id, (int)$sub['plan_id'], !$lastPaid, false);
$total   = $fees['total'];
$baseFee = $fees['base_fee'];
$gstPct  = $fees['gst_percent'];
$gstAmt  = $fees['gst_amount'];
$lateFee = $fees['late_fee'];

// 4) Payment & proof state
$payment_id = getOrCreatePayment($conn, $student_id, $nextDueISO, $total);
$proof      = getLatestProof($conn, $payment_id);

// Debug: log and on-page
$rawStatus = strtoupper($proof['status'] ?? 'NONE');
error_log("DEBUG: payment_id={$payment_id}, proof status={$rawStatus}");

$showUpload = $showPending = $showActive = false;
$bannerLine = '';
switch ($rawStatus) {
    case 'NONE':
        $showUpload = true; break;
    case 'PENDING':
        $showPending = true;
        $bannerLine  = 'You uploaded on ' . formatDate($proof['uploaded_at']);
        break;
    case 'REJECTED':
        $showUpload  = true;
        $bannerLine  = 'Last proof rejected: ' . htmlspecialchars($proof['rejection_reason']);
        break;
    case 'APPROVED':
        $showActive  = true;
        $bannerLine  = 'You uploaded on ' . formatDate($proof['uploaded_at']);
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><title>My Payment</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <!-- On-page debug -->
    <div class="alert alert-secondary">
      DEBUG: proof status = <strong><?= htmlspecialchars($rawStatus) ?></strong>
    </div>

    <?php if ($bannerLine): ?>
      <div class="alert alert-info"><?= $bannerLine ?></div>
    <?php endif; ?>

    <div class="card mb-4 bg-primary text-white text-center">
      <div class="card-body">
        <h3>Payment Overview</h3>
        <p>Next Due: <strong><?= $nextDueLabel ?></strong></p>
      </div>
    </div>

    <div class="card mx-auto" style="max-width:600px">
      <div class="card-body text-center">
        <h5>Current Plan: <?= htmlspecialchars($sub['plan_name']) ?> (<?= $sub['duration_months'] ?>M)</h5>
        <p class="display-5">₹<?= number_format($total,2) ?></p>
        <p class="text-muted">
          Base: ₹<?= number_format($baseFee,2) ?> · GST <?= $gstPct ?>%: ₹<?= number_format($gstAmt,2) ?>
          <?php if ($lateFee>0): ?>· Late: ₹<?= number_format($lateFee,2) ?><?php endif; ?>
        </p>
        <?php if ($showUpload): ?>
          <a href="upload_payment_proof.php" class="btn btn-primary">Upload Proof</a>
        <?php elseif ($showPending): ?>
          <button class="btn btn-warning" disabled>Pending Approval</button>
        <?php elseif ($showActive): ?>
          <button class="btn btn-success" disabled>Active</button>
          <a href="student_invoice.php" class="btn btn-dark">Invoice</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
