<?php
// File: actions/approve_payment_proof.php

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');

require_once __DIR__ . '/../config/db.php';                       // $conn
require_once __DIR__ . '/../dashboard/includes/functions.php';    // create_notification()
require_once __DIR__ . '/../dashboard/includes/fee_calculator.php'; // calculate_student_fee()

$id  = (int)($_GET['id']  ?? 0);
$act = ( ($_GET['a'] ?? '') === 'approve' ) ? 'approve' : 'reject';

if (!$id) {
    exit('Invalid proof ID');
}

// load proof
$stmt = $conn->prepare("
  SELECT student_id, amount 
    FROM payment_proofs
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($studentId, $reportedAmount);
if (!$stmt->fetch()) {
    $stmt->close();
    exit('Proof not found');
}
$stmt->close();

if ($act === 'approve') {
    // mark proof
    $u = $conn->prepare("
      UPDATE payment_proofs
         SET status      = 'Approved',
             approved_at = NOW()
       WHERE id = ?
    ");
    $u->bind_param('i', $id);
    $u->execute();
    $u->close();

    // same fee‐recalc flow as above...
    // 1) subscription
    $s = $conn->prepare("
      SELECT plan_id
        FROM student_subscriptions
       WHERE student_id=?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $s->bind_param('i', $studentId);
    $s->execute();
    $s->bind_result($planId);
    $s->fetch();
    $s->close();

    // 2) first‐time?
    $c = $conn->prepare("
      SELECT COUNT(*) 
        FROM payments
       WHERE student_id=? AND status='Paid'
    ");
    $c->bind_param('i', $studentId);
    $c->execute();
    $c->bind_result($paidCount);
    $c->fetch();
    $c->close();
    $isFirstPaid = ($paidCount === 0);

    // 3) late?
    $today = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $isLate = ((int)$today->format('j') > 5);

    // 4) compute
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
       WHERE id=?
    ");
    $sk->bind_param('i', $studentId);
    $sk->execute();
    $sk->bind_result($skipEnroll, $skipAdvance);
    $sk->fetch();
    $sk->close();
    if ($skipEnroll)  $detail['enrollment_fee'] = 0.0;
    if ($skipAdvance) $detail['advance_fee']    = 0.0;

    $newTotal = (float)$detail['total'];

    // update payments
    $p = $conn->prepare("
      UPDATE payments
         SET status='Paid',
             amount_due=?,
             amount_paid=?,
             paid_at=NOW()
       WHERE student_id=?
         AND status='Pending'
       ORDER BY payment_id DESC
       LIMIT 1
    ");
    $p->bind_param('ddi', $newTotal, $newTotal, $studentId);
    $p->execute();
    $p->close();

    // notify
    create_notification(
      $conn,
      [ $studentId ],
      'Payment Approved',
      sprintf(
        "Your payment proof #%d for ₹%.2f has been approved. Your updated total due is ₹%.2f.",
        $id, $reportedAmount, $newTotal
      )
    );
}
else {
    // reject
    $r = $conn->prepare("
      UPDATE payment_proofs
         SET status='Rejected'
       WHERE id=?
    ");
    $r->bind_param('i', $id);
    $r->execute();
    $r->close();

    create_notification(
      $conn,
      [ $studentId ],
      'Payment Rejected',
      sprintf("Your payment proof #%d has been rejected.", $id)
    );
}

// back to admin table
header('Location: /artovue/dashboard/admin/?page=admin_payment');
exit;
