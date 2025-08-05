<?php
// File: dashboard/student/student_payment.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/fee_calculator.php';

$student_id = (int)($_SESSION['student_id'] ?? 0);
if (!$student_id) {
    header('Location:/artovue/login.php');
    exit;
}

// ─── 1) Load latest subscription & plan ─────────────────────────────────
$stmt = $conn->prepare(<<<'SQL'
SELECT ss.subscribed_at, ss.plan_id, p.plan_name, p.duration_months
  FROM student_subscriptions ss
  JOIN payment_plans p ON p.id = ss.plan_id
 WHERE ss.student_id = ?
 ORDER BY ss.subscribed_at DESC
 LIMIT 1
SQL
);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sub) {
    echo '<p class="p-8 text-center text-red-600">No active subscription found.</p>';
    exit;
}

// ─── 2) Find last Paid payment (if any) ─────────────────────────────────
$stmt = $conn->prepare(<<<'SQL'
SELECT payment_id, amount_paid, paid_at, due_date
  FROM payments
 WHERE student_id = ? AND status = 'Paid'
 ORDER BY due_date DESC
 LIMIT 1
SQL
);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$lastPaid = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// if no paid ever, treat as “new”
$isNew = !$lastPaid;

// ─── 3) Compute next‐due date and late flag ──────────────────────────────
$tz    = new DateTimeZone('Asia/Kolkata');
if ($lastPaid) {
    // start from last cycle’s due_date
    $dueDt = new DateTime($lastPaid['due_date'], $tz);
} else {
    // first cycle: start from subscription
    $dueDt = new DateTime($sub['subscribed_at'], $tz);
}
$dueDt->modify('+' . $sub['duration_months'] . ' months')
      ->setDate((int)$dueDt->format('Y'), (int)$dueDt->format('m'), 5);

$nextDueISO   = $dueDt->format('Y-m-d');
$nextDueLabel = $dueDt->format('M j, Y');

// more than 2 days late?
$grace = (clone $dueDt)->modify('+2 days');
$isLatePayment = !$isNew && (new DateTime('now', $tz) > $grace);

// ─── 4) Fee breakdown ────────────────────────────────────────────────────
$fees = calculate_student_fee(
  $conn,
  $student_id,
  (int)$sub['plan_id'],
  $isNew,
  $isLatePayment
);

$totalDisplay  = $fees['total'];
$baseFee       = $fees['base_fee'];
$enrollFee     = $fees['enrollment_fee'];
$advanceFee    = $fees['advance_fee'];
$gstPct        = $fees['gst_percent'];
$gstAmt        = $fees['gst_amount'];
$lateFee       = $fees['late_fee'];

// ─── 5) Is there a pending “payments” row for this cycle? ────────────────
$stmt = $conn->prepare("
  SELECT payment_id 
    FROM payments
   WHERE student_id=? 
     AND status    = 'Pending' 
     AND due_date  = ?
   LIMIT 1
");
$stmt->bind_param('is', $student_id, $nextDueISO);
$stmt->execute();
$pending = $stmt->get_result()->fetch_assoc() ?: null;
$stmt->close();

// if none pending, but lastPaid → they already paid & system never created pending row
if (!$pending && $lastPaid && $lastPaid['due_date'] === $nextDueISO) {
    $pending = ['payment_id'=>$lastPaid['payment_id']];
}

// ─── 6) Decide which button/badge to show ─────────────────────────────────
$showUpload   = false;
$showPending  = false;
$showRejected = false;
$showActive   = false;
$proof        = null;

// “You paid…” banner only if we have a lastPaid and no pending
$paidLine = '';
if ($lastPaid && !$pending) {
    $dtPaid    = new DateTime($lastPaid['paid_at'], $tz);
    $paidLine  = sprintf(
      'You paid <strong>₹%.2f</strong> on %s',
      $lastPaid['amount_paid'],
      $dtPaid->format('M j, Y')
    );
}

if ($pending) {
    // fetch proof by payment_id
    $stmt = $conn->prepare("
      SELECT status, rejection_reason
        FROM payment_proofs
       WHERE payment_id = ?
       ORDER BY uploaded_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $pending['payment_id']);
    $stmt->execute();
    $proof = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (! $proof) {
        $showUpload  = true;
    } elseif ($proof['status'] === 'Pending') {
        $showPending = true;
    } elseif ($proof['status'] === 'Rejected') {
        $showRejected = true;
    } else {
        $showActive   = true;  // Approved
    }

} else {
    // no pending row
    if ($lastPaid) {
        $showActive = true;
    } else {
        $showUpload = true;
    }
}

?><!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"/><title>My Payment</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
</head>
<body class="bg-gray-100">
  <div class="max-w-4xl mx-auto py-12 px-4 space-y-8">
    <div class="bg-purple-600 text-white rounded-lg p-8 text-center shadow">
      <h1 class="text-2xl font-semibold mb-2">Payment Overview</h1>
      <?php if ($paidLine): ?>
        <p class="text-lg"><?= $paidLine ?></p>
      <?php endif; ?>
      <p class="mt-2 text-lg">
        Next Due: <strong><?= htmlspecialchars($nextDueLabel) ?></strong>
      </p>
    </div>
    <div class="bg-white rounded-lg p-8 shadow text-center space-y-4">
      <h2 class="text-xl font-medium">
        Current Plan: <?= htmlspecialchars($sub['plan_name']) ?>
        (<?= (int)$sub['duration_months'] ?>-Month)
      </h2>
      <p class="text-5xl font-bold">₹<?= number_format($totalDisplay,2) ?></p>
      <p class="text-gray-600">
        Base: ₹<?= number_format($baseFee,2) ?>
        <?php if($enrollFee>0): ?>&nbsp;·&nbsp;Enroll: ₹<?= number_format($enrollFee,2) ?><?php endif; ?>
        <?php if($advanceFee>0):?>&nbsp;·&nbsp;Advance: ₹<?= number_format($advanceFee,2) ?><?php endif; ?>
        &nbsp;·&nbsp;GST <?= number_format($gstPct,2) ?>%: ₹<?= number_format($gstAmt,2) ?>
        <?php if($lateFee>0): ?>&nbsp;·&nbsp;Late: ₹<?= number_format($lateFee,2) ?><?php endif; ?>
      </p>
      <div class="mt-6 space-x-4">
        <?php if ($showUpload): ?>
          <a href="upload_payment_proof.php" class="inline-block bg-blue-600 text-white px-6 py-3 rounded-lg">
            <i class="bi bi-upload mr-2"></i> Upload Proof
          </a>
        <?php elseif ($showPending): ?>
          <span class="inline-block bg-yellow-100 text-yellow-800 px-4 py-2 rounded-lg">
            <i class="bi bi-hourglass-split mr-2"></i> Pending Approval
          </span>
        <?php elseif ($showRejected): ?>
          <span class="inline-block bg-red-100 text-red-800 px-4 py-2 rounded-lg">
            <i class="bi bi-x-circle mr-2"></i> Rejected
          </span>
        <?php elseif ($showActive): ?>
          <button class="bg-green-600 text-white px-6 py-3 rounded-lg inline-flex items-center">
            <i class="bi bi-check-circle-fill mr-2"></i> Active
          </button>
          <a href="student_invoice.php?student_id=<?= $student_id ?>"
             class="bg-gray-900 text-white px-6 py-3 rounded-lg inline-flex items-center">
            <i class="bi bi-file-earmark-arrow-down mr-2"></i> Invoice
          </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>
