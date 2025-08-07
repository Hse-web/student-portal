<?php
// File: dashboard/student/student_invoice.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

require_once __DIR__ . '/../includes/functions.php';       // compute_student_due()
require_once __DIR__ . '/../includes/fee_calculator.php';  // calculate_student_fee()

// ─── 1) Identify student ─────────────────────────────────────────────
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    exit('Invalid student.');
}

// ─── 2) Fetch latest approved proof + its payment & original upload time ───
$stmt = $conn->prepare("
  SELECT 
    pp.id             AS proof_id,
    pp.payment_id     AS payment_id,
    pp.payment_method AS method,
    pp.txn_id         AS txn_id,
    pp.uploaded_at    AS uploaded_at,
    p.amount_paid     AS amount_paid,
    p.paid_at         AS paid_at,
    p.due_date        AS payment_due_date
  FROM payment_proofs pp
  JOIN payments p 
    ON p.student_id = pp.student_id
   AND p.status     = 'Paid'
   AND p.payment_id = pp.payment_id
  WHERE pp.student_id = ?
    AND pp.status     = 'Approved'
  ORDER BY pp.approved_at DESC
  LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result(
  $proofId,
  $paymentId,
  $method,
  $txnId,
  $uploadedAt,
  $amountPaid,
  $paidAt,
  $payment_due_date
);
if (!$stmt->fetch()) {
    $stmt->close();
    exit('No approved payment found. Please upload a proof or wait for approval.');
}
$stmt->close();

// ─── 3) Fetch latest subscription & plan ──────────────────────────────
$stmt = $conn->prepare("
  SELECT 
    s.subscribed_at,
    s.plan_id,
    p.plan_name,
    p.duration_months,
    p.amount          AS base_amount,
    p.late_fee,
    p.gst_percent,
    p.enrollment_fee,
    p.advance_fee
  FROM student_subscriptions s
  JOIN payment_plans p 
    ON p.id = s.plan_id
  WHERE s.student_id = ?
  ORDER BY s.subscribed_at DESC
  LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result(
  $subscribedAt,
  $planId,
  $planName,
  $durationMonths,
  $planBaseAmt,
  $planLateFee,
  $planGstPct,
  $planEnrollFee,
  $planAdvanceFee
);
$stmt->fetch();
$stmt->close();
if (!$planId) {
    exit('No active subscription found.');
}

// ─── 4) Count “Paid” rows to detect first invoice ──────────────────────
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM payments
   WHERE student_id = ?
     AND status     = 'Paid'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($paidCount);
$stmt->fetch();
$stmt->close();
$isFirstPaid = ($paidCount === 1);

// ─── 5) Late‐fee flag: day‐of‐month > 5 ? ──────────────────────────────
$dtPaid = new DateTime($paidAt);
$isLate = ((int)$dtPaid->format('j') > 5);

// ─── 6) Compute fee breakdown ─────────────────────────────────────────
$feeDetail = calculate_student_fee(
    $conn,
    $studentId,
    $planId,
    $isFirstPaid,
    $isLate
);
// double‐check skip‐flags
$stmt = $conn->prepare("
  SELECT skip_enroll_fee, skip_advance_fee
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($skipEnroll, $skipAdvance);
$stmt->fetch();
$stmt->close();
if ($skipEnroll)  $feeDetail['enrollment_fee'] = 0.00;
if ($skipAdvance) $feeDetail['advance_fee']    = 0.00;
// recompute GST & total
$subTotal = $feeDetail['base_fee']
          + $feeDetail['enrollment_fee']
          + $feeDetail['advance_fee']
          + $feeDetail['late_fee'];
$feeDetail['gst_amount'] = round($subTotal * ($feeDetail['gst_percent']/100), 2);
$feeDetail['total']      = round($subTotal + $feeDetail['gst_amount'], 2);

// ─── 7) Compute Next Due on the 5th of next period based on payment's due_date ───
$dtNext = new DateTime($payment_due_date);
$dtNext->modify("+{$durationMonths} months");
$dtNext->setDate(
    (int)$dtNext->format('Y'),
    (int)$dtNext->format('m'),
    5
);
$nextDueDate = $dtNext->format('M j, Y');

// ─── 8) Invoice number & student info ─────────────────────────────────
$invoiceNo = date('Ymd') . $proofId;
$stmt = $conn->prepare("
  SELECT name, email, phone, group_name
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName, $studentEmail, $studentPhone, $studentGroup);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice #<?= htmlspecialchars($invoiceNo) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <style>
    body{font-family:Arial,sans-serif;margin:1rem;color:#333;}
    .flex-between{display:flex;justify-content:space-between;align-items:flex-start;}
    table{border-collapse:collapse;width:100%;margin-top:1rem;}
    th,td{border:1px solid #666;padding:.5rem;}
    th{background:#f0f0f0;text-align:left;}
    .text-right{text-align:right;}
    .section{margin-top:2rem;}
    .overview-card{background:#1CBFFF;color:#fff;padding:1rem;border-radius:.25rem;}
    @media print{.no-print{display:none;}}
  </style>
</head>
<body>

  <div class="no-print text-end mb-3">
    <button class="btn btn-success" onclick="window.print()">
      Print
    </button>
  </div>

  <h2>PAYMENT INVOICE</h2>

  <div class="overview-card text-center mb-4">
    <h5>Payment Overview</h5>
    <p class="mb-1">
      Current Plan Fee: <strong>₹<?= number_format($feeDetail['total'],0) ?></strong>
    </p>
    <small>Next Due: <?= htmlspecialchars($nextDueDate) ?></small>
  </div>

  <div class="flex-between">
    <div>
       <strong>To:</strong><br>
      Rartworks, 2nd floor 12, Kaveri street, opposite to shell petrol pump, <br>Basavanagara, Bengaluru, Karnataka 560037.<br>
      Mobile: +91-8431983282<br>
      <b>GSTIN: 29CRMPP1129G1ZJ</b>
    </div>
    <div class="text-end">
      <strong>Invoice #:</strong> <?= htmlspecialchars($invoiceNo) ?><br>
      <strong>Date:</strong> <?= date('M j, Y', strtotime($uploadedAt)) ?><br><br>
      <strong>From Student:</strong><br>
      <?= htmlspecialchars($studentName) ?><br>
      <?= htmlspecialchars($studentEmail) ?><br>
      <?= htmlspecialchars($studentPhone) ?><br>
      <strong>Plan:</strong> <?= htmlspecialchars($planName) ?><br>
      <strong>Group:</strong> <?= htmlspecialchars($studentGroup) ?><br>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th class="text-right">Amount Paid (₹)</th>
        <th>Method</th>
        <th>Txn ID</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><?= date('M j, Y', strtotime($uploadedAt)) ?></td>
        <td class="text-right"><?= number_format($amountPaid,0) ?></td>
        <td><?= htmlspecialchars($method) ?></td>
        <td><?= htmlspecialchars($txnId) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="section text-end">
    <table style="width:auto;margin-left:auto;">
      <tr><th>Duration:</th>
          <td><?= htmlspecialchars($durationMonths) ?> month(s)</td></tr>
      <tr><th>Tuition Fee (Base):</th>
          <td>₹<?= number_format($feeDetail['base_fee'],0) ?></td></tr>
      <?php if($feeDetail['enrollment_fee']>0): ?>
      <tr><th>Enrollment Fee:</th>
          <td>₹<?= number_format($feeDetail['enrollment_fee'],0) ?></td></tr>
      <?php endif; ?>
      <?php if($feeDetail['advance_fee']>0): ?>
      <tr><th>Advance Fee:</th>
          <td>₹<?= number_format($feeDetail['advance_fee'],0) ?></td></tr>
      <?php endif; ?>
      <?php if($feeDetail['late_fee']>0): ?>
      <tr><th>Late Fee:</th>
          <td>₹<?= number_format($feeDetail['late_fee'],0) ?></td></tr>
      <?php endif; ?>
      <tr><th>GST (<?= htmlspecialchars($feeDetail['gst_percent']) ?>%):</th>
          <td>₹<?= number_format($feeDetail['gst_amount'],0) ?></td></tr>
      <tr><th><strong>Total Paid:</strong></th>
          <td><strong>₹<?= number_format($amountPaid,0) ?></strong></td></tr>
    </table>
  </div>

</body>
</html>
