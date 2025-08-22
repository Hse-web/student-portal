<?php
// File: dashboard/student/student_invoice.php

$page = 'student_invoice';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fee_calculator.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) { exit('Invalid student.'); }

// ─────────────────────────────────────────────────────────────────────────────
// 0) If proof_id is provided, use that specific approved proof belonging
//    to this student; otherwise fall back to "latest approved".
// ─────────────────────────────────────────────────────────────────────────────
$requestedProofId = isset($_GET['proof_id']) ? (int)$_GET['proof_id'] : 0;

if ($requestedProofId > 0) {
  $stmt = $conn->prepare("
    SELECT 
      pp.id              AS proof_id,
      pp.payment_method  AS method,
      pp.txn_id          AS txn_id,
      pp.approved_at     AS approved_at,
      p.amount_paid      AS amount_paid,
      p.paid_at          AS paid_at,
      p.payment_id       AS payment_id
    FROM payment_proofs pp
    JOIN payments p ON p.payment_id = pp.payment_id
    WHERE pp.student_id = ?
      AND pp.id = ?
      AND pp.status = 'Approved'
    LIMIT 1
  ");
  $stmt->bind_param('ii', $studentId, $requestedProofId);
} else {
  $stmt = $conn->prepare("
    SELECT 
      pp.id              AS proof_id,
      pp.payment_method  AS method,
      pp.txn_id          AS txn_id,
      pp.approved_at     AS approved_at,
      p.amount_paid      AS amount_paid,
      p.paid_at          AS paid_at,
      p.payment_id       AS payment_id
    FROM payment_proofs pp
    JOIN payments p 
      ON p.payment_id = pp.payment_id
    WHERE pp.student_id = ?
      AND pp.status     = 'Approved'
    ORDER BY pp.approved_at DESC
    LIMIT 1
  ");
  $stmt->bind_param('i', $studentId);
}
$stmt->execute();
$stmt->bind_result($proofId, $method, $txnId, $approvedAt, $amountPaid, $paidAt, $paymentId);
if (!$stmt->fetch()) {
  $stmt->close();
  exit('No approved payment found. Please upload a payment proof or wait for approval.');
}
$stmt->close();

// ─── 1) Most recent subscription & plan info ────────────────────────────────
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
  JOIN payment_plans p ON p.id = s.plan_id
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
if (!$planId) { exit('No active subscription found for this student.'); }

// ─── 2) Is this the first Paid ever? ────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'Paid'");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($paidCount);
$stmt->fetch();
$stmt->close();
$isFirstPaid = ($paidCount === 1);

// ─── 3) Fee breakdown (late only if paid after 5th) ─────────────────────────
$dtPaid  = new DateTime($paidAt);
$isLate  = ((int)$dtPaid->format('j') > 5);

$feeDetail = calculate_student_fee(
  $conn,
  $studentId,
  $planId,
  $isFirstPaid,
  $isLate
);

// Honor skip flags defensively
$stmt = $conn->prepare("SELECT skip_enroll_fee, skip_advance_fee FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($skipEnrollFlag, $skipAdvanceFlag);
$stmt->fetch();
$stmt->close();
if ($skipEnrollFlag)  $feeDetail['enrollment_fee'] = 0.00;
if ($skipAdvanceFlag) $feeDetail['advance_fee']    = 0.00;

$subTotal = $feeDetail['base_fee']
          + $feeDetail['enrollment_fee']
          + $feeDetail['advance_fee']
          + $feeDetail['late_fee'];
$feeDetail['gst_amount'] = round($subTotal * ($feeDetail['gst_percent'] / 100), 2);
$feeDetail['total']      = round($subTotal + $feeDetail['gst_amount'], 2);

// ─── 4) Next due = paidAt + duration (shows future anchor date) ────────────
$dtNextDue = new DateTime($paidAt);
$dtNextDue->modify("+{$durationMonths} months");
$nextDueDate = $dtNextDue->format('M j, Y');

// ─── 5) Student details ─────────────────────────────────────────────────────
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

// ─── 6) Invoice number (date + proof id) ────────────────────────────────────
$invoiceNo = date('Ymd', strtotime($approvedAt)) . $proofId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice #<?= htmlspecialchars($invoiceNo) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{font-family:Arial,sans-serif;margin:1rem;color:#333}
    .flex-between{display:flex;justify-content:space-between;align-items:flex-start}
    table{border-collapse:collapse;width:100%;margin-top:1rem}
    th,td{border:1px solid #666;padding:.5rem}
    th{background:#f0f0f0;text-align:left}
    .text-right{text-align:right}
    .section{margin-top:2rem}
    @media print{.no-print{display:none}}
    .overview-card{background:#1CBFFF;color:#fff;padding:1rem;border-radius:.25rem}
    .brand{display:flex;gap:.75rem;align-items:center}
    .brand img{height:36px}
  </style>
</head>
<body>

<div class="no-print mb-3 text-end">
  <button class="btn btn-success" onclick="window.print()">Print</button>
</div>

<h2 class="brand">
  <img src="/assets/logo.png" alt="Logo" onerror="this.style.display='none'">
  PAYMENT INVOICE
</h2>

<div class="overview-card mb-4 text-center">
  <h5>Payment Overview</h5>
  <p class="mb-1">Current Plan Fee: <strong>₹<?= number_format($feeDetail['total'], 0) ?></strong></p>
  <small>Next Due: <?= htmlspecialchars($nextDueDate) ?></small>
</div>

<div class="flex-between">
  <div>
    <strong>To:</strong><br>
    R Art Works, Bengaluru<br>
    GSTIN: 29AABCC1234H1ZJ
  </div>
  <div class="text-end">
    <strong>Invoice #:</strong> <?= htmlspecialchars($invoiceNo) ?><br>
    <strong>Date:</strong> <?= date('M j, Y', strtotime($approvedAt)) ?><br><br>
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
      <td><?= date('M j, Y', strtotime($paidAt)) ?></td>
      <td class="text-right"><?= number_format((float)$amountPaid, 0) ?></td>
      <td><?= htmlspecialchars((string)$method) ?></td>
      <td><?= htmlspecialchars((string)$txnId) ?></td>
    </tr>
  </tbody>
</table>

<div class="section text-end">
  <table style="width:auto;margin-left:auto;">
    <tr><th>Duration:</th><td><?= htmlspecialchars($durationMonths) ?> month(s)</td></tr>
    <tr><th>Tuition Fee (Base):</th><td>₹<?= number_format($feeDetail['base_fee'], 0) ?></td></tr>
    <?php if ($feeDetail['enrollment_fee'] > 0): ?>
      <tr><th>Enrollment Fee:</th><td>₹<?= number_format($feeDetail['enrollment_fee'], 0) ?></td></tr>
    <?php endif; ?>
    <?php if ($feeDetail['advance_fee'] > 0): ?>
      <tr><th>Advance Fee:</th><td>₹<?= number_format($feeDetail['advance_fee'], 0) ?></td></tr>
    <?php endif; ?>
    <?php if ($feeDetail['late_fee'] > 0): ?>
      <tr><th>Late Fee:</th><td>₹<?= number_format($feeDetail['late_fee'], 0) ?></td></tr>
    <?php endif; ?>
    <tr><th>GST (<?= htmlspecialchars($feeDetail['gst_percent']) ?>%):</th><td>₹<?= number_format($feeDetail['gst_amount'], 0) ?></td></tr>
    <tr><th><strong>Total Paid:</strong></th><td><strong>₹<?= number_format((float)$amountPaid, 0) ?></strong></td></tr>
  </table>
</div>

</body>
</html>
