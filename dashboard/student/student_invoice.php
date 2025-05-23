<?php
// File: dashboard/student/student_invoice.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

$studentId = (int)($_GET['student_id'] ?? 0);
if (!$studentId) exit('Invalid student.');

// 1. Fetch latest approved payment proof
$stmt = $conn->prepare("
  SELECT pp.id, pp.payment_method, pp.txn_id, pp.approved_at,
         p.amount_paid
    FROM payment_proofs pp
    JOIN payments p ON p.student_id = pp.student_id
                  AND p.status = 'Paid'
   WHERE pp.student_id = ?
     AND pp.status = 'Approved'
   ORDER BY pp.approved_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($proofId, $method, $txnId, $paidAt, $amountPaid);
if (!$stmt->fetch()) exit('No approved payment found.');
$stmt->close();

// 2. Latest subscription & plan info
$stmt = $conn->prepare("
  SELECT s.subscribed_at, p.duration_months, p.amount,
         p.enrollment_fee, p.advance_fee, p.gst_percent, p.plan_name
    FROM student_subscriptions s
    JOIN payment_plans p ON p.id = s.plan_id
   WHERE s.student_id = ?
   ORDER BY s.subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($subAt, $duration, $planAmt, $enrollFee, $advanceFee, $gstPct, $planName);
$stmt->fetch();
$stmt->close();

// 3. Is this the first subscription?
$stmt = $conn->prepare("SELECT COUNT(*) FROM student_subscriptions WHERE student_id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($subCount);
$stmt->fetch();
$stmt->close();
$isFirst = ($subCount <= 1);

// 4. Fetch student info
$stmt = $conn->prepare("SELECT name, email, phone, group_name, is_legacy FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($name, $email, $phone, $groupName, $isLegacy);
$stmt->fetch();
$stmt->close();

// 5. Calculate breakdown
$tuition = $planAmt;
$includeOneTime = ($isFirst && !$isLegacy);
$oneTime = $includeOneTime ? ($enrollFee + $advanceFee) : 0.00;
$subtotal = $tuition + $oneTime;
$gstAmount = round($subtotal * ($gstPct / 100), 2);
$totalDue = $subtotal + $gstAmount;
$invoiceNo = date('Ymd') . $proofId;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Invoice #<?= $invoiceNo ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
      <strong>Invoice #:</strong> <?= $invoiceNo ?><br>
      <strong>Date:</strong> <?= date('M j, Y', strtotime($paidAt)) ?><br><br>
      <strong>From Student:</strong><br>
      <?= htmlspecialchars($name) ?><br>
      <?= htmlspecialchars($email) ?><br>
      <?= htmlspecialchars($phone) ?><br>
      <strong>Plan:</strong> <?= htmlspecialchars($planName) ?><br>
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
        <td class="text-right">₹<?= number_format($amountPaid, 2) ?></td>
        <td><?= htmlspecialchars($method) ?></td>
        <td><?= htmlspecialchars($txnId) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="section text-end">
    <table style="width:auto; margin-left:auto;">
      <tr><th>Duration:</th><td><?= $duration ?> month(s)</td></tr>
      <tr><th>Tuition Fee:</th><td>₹<?= number_format($planAmt, 2) ?></td></tr>
  <?php if ($includeOneTime): ?>
  <tr><th>Enrollment Fee:</th><td>₹<?= number_format($enrollFee, 2) ?></td></tr>
  <tr><th>Advance Fee:</th><td>₹<?= number_format($advanceFee, 2) ?></td></tr>
<?php endif; ?>
      <tr><th>GST (<?= $gstPct ?>%):</th><td>Included in final amount</td></tr>
      <tr>
        <th><strong>Total Paid:</strong></th>
        <td><strong>₹<?= number_format($amountPaid, 2) ?></strong></td>
      </tr>
    </table>
  </div>

</body>
</html>
