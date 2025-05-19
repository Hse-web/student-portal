<?php
// File: dashboard/student/student_invoice.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

// ─── 1) Identify & validate student ───────────────────────────────
$studentId = (int)($_GET['student_id'] ?? 0);
if (!$studentId) {
    exit('Invalid student.');
}

// ─── 2) Fetch latest approved payment + proof ─────────────────────
$stmt = $conn->prepare("
    SELECT
      p.paid_at,
      p.amount_paid,
      pp.id            AS proof_id,
      pp.payment_method,
      pp.txn_id
    FROM payments       AS p
    JOIN payment_proofs AS pp
      ON pp.student_id = p.student_id
    WHERE p.student_id = ?
      AND p.status     = 'Paid'
      AND pp.status    = 'Approved'
    ORDER BY p.paid_at DESC
    LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result(
  $paidAt,
  $paidAmt,
  $proofId,
  $method,
  $txnId
);
if (!$stmt->fetch()) {
    exit('No approved payment found.');
}
$stmt->close();

// ─── 3) Fetch subscription + fee settings ─────────────────────────
$stmt = $conn->prepare("
    SELECT 
      s.subscribed_at,
      p.duration_months,
      p.amount       AS plan_amt,
      c.enrollment_fee,
      c.advance_fee,
      c.gst_percent
    FROM student_subscriptions AS s
    JOIN payment_plans          AS p ON p.id          = s.plan_id
    JOIN center_fee_settings    AS c ON c.centre_id   = (
      SELECT centre_id FROM students WHERE id = ?
    )
    WHERE s.student_id = ?
    ORDER BY s.subscribed_at DESC
    LIMIT 1
");
$stmt->bind_param('ii', $studentId, $studentId);
$stmt->execute();
$stmt->bind_result(
  $subAt,
  $dur,
  $planAmt,
  $enrollFee,
  $advanceFee,
  $gstPct
);
$stmt->fetch();
$stmt->close();

// ─── 4) Is this their very first subscription? ────────────────────
$stmt = $conn->prepare("
    SELECT COUNT(*) 
      FROM student_subscriptions 
     WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($subsCount);
$stmt->fetch();
$stmt->close();
$isFirst = ($subsCount <= 1);

// ─── 5) Compute line-item totals ─────────────────────────────────
$tuition  = $planAmt;                       // full plan
$oneTime  = $isFirst ? ($enrollFee + $advanceFee) : 0;
$subtotal = $tuition + $oneTime;
$gstAmt   = round($subtotal * ($gstPct / 100));
$totalDue = $subtotal + $gstAmt;

// ─── 6) Pull student header info ─────────────────────────────────
$stmt = $conn->prepare("
    SELECT name, email, phone, group_name
      FROM students
     WHERE id = ?
     LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $groupName);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice #<?= date('Ymd').$proofId ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" 
    rel="stylesheet">
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" 
    rel="stylesheet">
  <style>
    body { font-family:Arial,sans-serif; margin:1rem; color:#333; }
    .flex-between { display:flex; justify-content:space-between; }
    table { border-collapse:collapse; width:100%; margin-top:1rem; }
    th, td { border:1px solid #666; padding:.5rem; }
    th { background:#f0f0f0; text-align:left; }
    .text-right { text-align:right; }
    .section { margin-top:2rem; }
    @media print { .no-print { display:none; } }
  </style>
</head>
<body>

  <!-- Download / Print button -->
  <div class="no-print mb-3 text-end">
    <button class="btn btn-success" onclick="window.print()">
      <i class="bi bi-printer"></i> Download / Print
    </button>
  </div>

  <h2>PAYMENT INVOICE</h2>

  <div class="flex-between">
    <div>
      <strong>To:</strong><br>
      R Art Works, Bengaluru<br>
      GSTIN: 29AABCC1234H1ZJ
    </div>
    <div class="text-end">
      <strong>Invoice #:</strong> <?= date('Ymd').$proofId ?><br>
      <strong>Date:</strong> <?= date('M j, Y', strtotime($paidAt)) ?><br><br>
      <strong>From Student:</strong><br>
      <?= htmlspecialchars($name) ?><br>
      <?= htmlspecialchars($email) ?><br>
      <?= htmlspecialchars($phone) ?><br>
      <strong>Plan:</strong> 
        <?= $dur===1 ? 'Regular Works' 
            : ($dur<=3 ? 'Core Works' : 'Pro Works') 
        ?><br>
      <strong>Group:</strong> <?= htmlspecialchars($groupName) ?><br>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th class="text-right">Amount Paid</th>
        <th>Method</th>
        <th>Txn ID</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><?= date('M j, Y', strtotime($paidAt)) ?></td>
        <td class="text-right">₹<?= number_format($paidAmt,2) ?></td>
        <td><?= htmlspecialchars($method) ?></td>
        <td><?= htmlspecialchars($txnId) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="section text-end">
    <table style="width:auto; margin-left:auto;">
      <tr><th>Duration:</th>        <td><?= $dur ?> m</td></tr>
      <tr><th>Tuition:</th>         <td>₹<?= number_format($tuition,2) ?></td></tr>
      <?php if ($isFirst): ?>
        <tr><th>Enrollment Fee:</th>  <td>₹<?= number_format($enrollFee,2) ?></td></tr>
        <tr><th>Advance Fee:</th>     <td>₹<?= number_format($advanceFee,2) ?></td></tr>
      <?php endif; ?>
      <tr><th>GST (<?= $gstPct ?>%):</th><td>₹<?= number_format($gstAmt,2) ?></td></tr>
      <tr>
        <th><strong>Total Due:</strong></th>
        <td><strong>₹<?= number_format($totalDue,2) ?></strong></td>
      </tr>
    </table>
  </div>

</body>
</html>
