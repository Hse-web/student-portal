<?php
// File: actions/admin_handle_proof.php

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';                       // provides $conn
require_once __DIR__ . '/../dashboard/includes/functions.php';    // create_notification()
require_once __DIR__ . '/../dashboard/includes/fee_calculator.php'; // calculate_student_fee()

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// CSRF
if (empty($_POST['_csrf']) || ! verify_csrf_token($_POST['_csrf'])) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$proofId         = (int) ($_POST['id'] ?? 0);
$action          = ($_POST['action'] === 'approve') ? 'approve' : 'reject';
$rejectionReason = trim($_POST['rejection_reason'] ?? '');

if ($proofId < 1) {
    exit('Invalid proof ID');
}

// 1) Load proof → student_id + reportedAmount
$stmt = $conn->prepare("
  SELECT student_id, amount 
    FROM payment_proofs 
   WHERE id = ? 
     AND status = 'Pending'
   LIMIT 1
");
$stmt->bind_param('i', $proofId);
$stmt->execute();
$stmt->bind_result($studentId, $reportedAmount);
if (!$stmt->fetch()) {
    $stmt->close();
    exit('Proof not found or already processed');
}
$stmt->close();

if ($action === 'approve') {
    // A) Mark proof Approved
    $u = $conn->prepare("
      UPDATE payment_proofs
         SET status = 'Approved',
             approved_at = NOW()
       WHERE id = ?
    ");
    $u->bind_param('i', $proofId);
    $u->execute();
    $u->close();

    // B) Recompute student's due
    // 1) latest subscription
    $s = $conn->prepare("
      SELECT plan_id, subscribed_at
        FROM student_subscriptions
       WHERE student_id = ?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $s->bind_param('i', $studentId);
    $s->execute();
    $s->bind_result($planId, $subAt);
    $s->fetch();
    $s->close();

    if (!$planId) {
        exit('No subscription found for that student');
    }

    // 2) count prior Paid invoices
    $c = $conn->prepare("
      SELECT COUNT(*) 
        FROM payments
       WHERE student_id = ?
         AND status = 'Paid'
    ");
    $c->bind_param('i', $studentId);
    $c->execute();
    $c->bind_result($paidCount);
    $c->fetch();
    $c->close();
    $isFirstPaid = ($paidCount === 0);

    // 3) late if day > 5
    $today  = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $isLate = ((int)$today->format('j') > 5);

    // 4) fee breakdown
    $detail = calculate_student_fee(
      $conn,
      $studentId,
      $planId,
      $isFirstPaid,
      $isLate
    );

    // 5) skip flags
    $sk = $conn->prepare("
      SELECT skip_enroll_fee, skip_advance_fee
        FROM students
       WHERE id = ?
       LIMIT 1
    ");
    $sk->bind_param('i', $studentId);
    $sk->execute();
    $sk->bind_result($skipEnroll, $skipAdvance);
    $sk->fetch();
    $sk->close();

    if ($skipEnroll)  $detail['enrollment_fee'] = 0.0;
    if ($skipAdvance) $detail['advance_fee']    = 0.0;

    // 6) final total
    $newTotal = (float)$detail['total'];

    // C) mark the most recent Pending payment Paid
    $p = $conn->prepare("
      UPDATE payments
         SET status     = 'Paid',
             amount_due = ?,
             amount_paid= ?,
             paid_at    = NOW()
       WHERE student_id = ?
         AND status     = 'Pending'
       ORDER BY payment_id DESC
       LIMIT 1
    ");
    $p->bind_param('ddi', $newTotal, $newTotal, $studentId);
    $p->execute();
    $p->close();

    // D) notify student
    $msg = sprintf(
      "Your payment proof #%d for ₹%.2f has been approved. Your updated total due is ₹%.2f.",
      $proofId,
      $reportedAmount,
      $newTotal
    );
    create_notification(
      $conn,
      [ $studentId ],
      'Payment Approved',
      $msg
    );

    header('Location: /artovue/dashboard/admin/?page=admin_payment&msg=approved');
    exit;
}

// Reject
if ($rejectionReason === '') {
    exit('Rejection reason is required.');
}

$r = $conn->prepare("
  UPDATE payment_proofs
     SET status           = 'Rejected',
         rejection_reason = ?
   WHERE id = ?
");
$r->bind_param('si', $rejectionReason, $proofId);
$r->execute();
$r->close();

// notify
$msg = sprintf(
  "Your payment proof #%d has been rejected. Reason: %s",
  $proofId,
  $rejectionReason
);
create_notification(
  $conn,
  [ $studentId ],
  'Payment Rejected',
  $msg
);

header('Location: /artovue/dashboard/admin/?page=admin_payment&msg=rejected');
exit;
