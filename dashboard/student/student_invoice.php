<?php
// File: dashboard/student/student_invoice.php

$page = 'student_invoice';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';            // ← so that $conn exists

require_once __DIR__ . '/../includes/functions.php';       // contains compute_student_due()
require_once __DIR__ . '/../includes/fee_calculator.php';   // contains calculate_student_fee()

// ─── 1) Identify the student from session ─────────────────────────────────
$studentId = (int) ($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    exit('Invalid student.');
}

// ─── 2) Fetch the *most recent* “Approved” payment_proof AND its associated payments row ───
// We do a JOIN from payment_proofs → payments to get:
//   - “approved_at” and “payment_method” + “txn_id” from payment_proofs
//   - “amount_paid” + “paid_at” from payments
//
// We ORDER BY pp.approved_at DESC so that we pick the very latest approved proof.
//
// Note: We will still re‐compute the “fee breakdown” in step 5, but we read “amount_paid” here
//       so that we can show the exact method/txn and the exact date they paid.
//
// If *no* approved proof exists (or no matching Paid row), we bail out.

$stmt = $conn->prepare("
  SELECT 
    pp.id            AS proof_id,
    pp.payment_method    AS method,
    pp.txn_id            AS txn_id,
    pp.approved_at        AS approved_at,
    p.amount_paid        AS amount_paid,
    p.paid_at            AS paid_at
  FROM payment_proofs pp
  JOIN payments p 
    ON p.student_id = pp.student_id
   AND p.status     = 'Paid'
  WHERE pp.student_id = ?
    AND pp.status     = 'Approved'
  ORDER BY pp.approved_at DESC
  LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($proofId, $method, $txnId, $approvedAt, $amountPaid, $paidAt);
if (! $stmt->fetch()) {
    $stmt->close();
    exit('No approved payment found. Please upload a payment proof or wait for approval.');
}
$stmt->close();

// ─── 3) Fetch this student’s *most recent subscription* and plan info ───────────────────────
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

// If for some reason they have no subscription, bail out.
if (! $planId) {
    exit('No active subscription found for this student.');
}

// ─── 4) Determine if this is the student’s *first paid invoice* ────────────────────────────
// If the student has exactly 1 “Paid” row in payments, that means this is the very first time
// they are paying. (We check COUNT = 1 because we have not yet flipped any Pending → Paid.)

$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM payments
   WHERE student_id = ?
     AND status = 'Paid'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($paidCount);
$stmt->fetch();
$stmt->close();

$isFirstPaid = ($paidCount === 1);

// ─── 5) Re‐compute the *exact fee breakdown* (Base + Late? + Enrollment? + Advance? + GST) ─
/*
    We will pass ($isFirstPaid, $isLate) into calculate_student_fee(), 
    which returns an array:
      [ 'base_fee' => ..., 'enrollment_fee' => ..., 'advance_fee' => ...,
        'late_fee' => ..., 'gst_percent' => ..., 'gst_amount' => ..., 'total' => ... ]
*/

// -- Step 5.a) Compute whether this payment was “late” using “day-of-month > 5”:
$dtPaid  = new DateTime($paidAt);
$paidDay = intval($dtPaid->format('j'));  // e.g. “6” for June 6
$isLate  = ($paidDay > 5);               // true if day-of-month is 6 or greater

// -- Step 5.b) Call calculate_student_fee() to get the breakdown.
$feeDetail = calculate_student_fee(
    $conn,
    $studentId,
    $planId,
    $isFirstPaid,
    $isLate
);

// -- Step 5.c) If the student has “skip_enroll_fee = 1” or “skip_advance_fee = 1” in the `students` table,
//      forcibly zero out those two components. Our calculator already zeros them out on renewals or skip flags,
//      but this is a double-check in case of legacy data.

$stmt = $conn->prepare("
  SELECT skip_enroll_fee, skip_advance_fee
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($skipEnrollFlag, $skipAdvanceFlag);
$stmt->fetch();
$stmt->close();

if ($skipEnrollFlag) {
    $feeDetail['enrollment_fee'] = 0.00;
}
if ($skipAdvanceFlag) {
    $feeDetail['advance_fee'] = 0.00;
}

// -- Finally, recompute “gst_amount” and “total” if we forcibly zeroed out enrollment/advance:
$subTotal = $feeDetail['base_fee']
          + $feeDetail['enrollment_fee']
          + $feeDetail['advance_fee']
          + $feeDetail['late_fee'];

$feeDetail['gst_amount'] = round($subTotal * ($feeDetail['gst_percent'] / 100), 2);
$feeDetail['total']      = round($subTotal + $feeDetail['gst_amount'], 2);

// ─── 6) Compute “Next Due Date” = one period after $paidAt, etc. ───────────────────────────
$dtNextDue = new DateTime($paidAt);
$dtNextDue->modify("+{$durationMonths} months");
$nextDueDate = $dtNextDue->format('M j, Y');

// ─── 7) Build the invoice number: YYYYMMDD + proof_id ───────────────────────────────────────
$invoiceNo = date('Ymd') . $proofId;

// ─── 8) Fetch basic student info to display “From Student:” block ─────────────────────────
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
    rel="stylesheet"
  >
  <style>
    body         { font-family: Arial, sans-serif; margin:1rem; color:#333; }
    .flex-between { display: flex; justify-content: space-between; align-items: flex-start; }
    table        { border-collapse: collapse; width: 100%; margin-top: 1rem; }
    th, td       { border: 1px solid #666; padding: .5rem; }
    th           { background: #f0f0f0; text-align: left; }
    .text-right  { text-align: right; }
    .section     { margin-top: 2rem; }
    @media print { .no-print { display: none; } }
    .overview-card { background: #1CBFFF; color: white; padding: 1rem; border-radius: .25rem; }
  </style>
</head>
<body>

  <!-- Print Button (hidden when printing) -->
  <div class="no-print mb-3 text-end">
    <button class="btn btn-success" onclick="window.print()">
      <i class="bi bi-printer"></i> Print
    </button>
  </div>

  <h2>PAYMENT INVOICE</h2>

  <!-- ───────────────── Payment Overview Banner ─────────────────────── -->
  <div class="overview-card mb-4 text-center">
    <h5><i class="bi bi-credit-card"></i> Payment Overview</h5>
    <p class="mb-1">
      Current Plan Fee: <strong>₹<?= number_format($feeDetail['total'], 0) ?></strong>
    </p>
    <small>Next Due: <?= htmlspecialchars($nextDueDate) ?></small>
  </div>

  <!-- ───────────────── “To:” and “Invoice Details” Block ─────────────── -->
  <div class="flex-between">
    <div>
      <strong>To:</strong><br>
      Rartworks, 2nd floor 12, Kaveri street, opposite to shell petrol pump, <br>Basavanagara, Bengaluru, Karnataka 560037
+91-8431983282<br>
      <b>GSTIN: 29CRMPP1129G1ZJ</b>
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

  <!-- ───────────────── “Payment Details” Table ──────────────────────── -->
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
        <td class="text-right"><?= number_format($amountPaid, 0) ?></td>
        <td><?= htmlspecialchars($method) ?></td>
        <td><?= htmlspecialchars($txnId) ?></td>
      </tr>
    </tbody>
  </table>

  <!-- ───────────────── “Fee Breakdown” Box ──────────────────────────── -->
  <div class="section text-end">
    <table style="width:auto; margin-left:auto;">
      <tr>
        <th>Duration:</th>
        <td><?= htmlspecialchars($durationMonths) ?> month(s)</td>
      </tr>
      <tr>
        <th>Tuition Fee (Base):</th>
        <td>₹<?= number_format($feeDetail['base_fee'], 0) ?></td>
      </tr>
      <?php if ($feeDetail['enrollment_fee'] > 0): ?>
      <tr>
        <th>Enrollment Fee:</th>
        <td>₹<?= number_format($feeDetail['enrollment_fee'], 0) ?></td>
      </tr>
      <?php endif; ?>

      <?php if ($feeDetail['advance_fee'] > 0): ?>
      <tr>
        <th>Advance Fee:</th>
        <td>₹<?= number_format($feeDetail['advance_fee'], 0) ?></td>
      </tr>
      <?php endif; ?>

      <?php if ($feeDetail['late_fee'] > 0): ?>
      <tr>
        <th>Late Fee:</th>
        <td>₹<?= number_format($feeDetail['late_fee'], 0) ?></td>
      </tr>
      <?php endif; ?>

      <tr>
        <th>GST (<?= htmlspecialchars($feeDetail['gst_percent']) ?>%):</th>
        <td>₹<?= number_format($feeDetail['gst_amount'], 0) ?></td>
      </tr>
      <tr>
        <th><strong>Total Paid:</strong></th>
        <td><strong>₹<?= number_format($amountPaid, 0) ?></strong></td>
      </tr>
    </table>
  </div>

</body>
</html>
