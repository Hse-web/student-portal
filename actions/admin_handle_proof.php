<?php
// File: actions/admin_handle_proof.php

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../dashboard/includes/functions.php';     // create_notification()
require_once __DIR__ . '/../dashboard/includes/fee_calculator.php'; // calculate_student_fee()

// 1) Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}
// 2) CSRF
if (empty($_POST['_csrf']) || ! verify_csrf_token($_POST['_csrf'])) {
    http_response_code(400);
    exit('Invalid CSRF token');
}

$proofId         = (int)($_POST['id'] ?? 0);
$action          = ($_POST['action'] === 'approve') ? 'approve' : 'reject';
$rejectionReason = trim($_POST['rejection_reason'] ?? '');

if ($proofId < 1) {
    exit('Invalid proof ID');
}

// 3) Load the proof
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
if (! $stmt->fetch()) {
    $stmt->close();
    exit('No pending proof found');
}
$stmt->close();

// 4) Try to find an existing “Pending” payment for this student
$stmt = $conn->prepare("
  SELECT payment_id, amount_due
    FROM payments
   WHERE student_id = ?
     AND status = 'Pending'
   ORDER BY payment_id DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($paymentId, $currentDue);
$hasInvoice = (bool)$stmt->fetch();
$stmt->close();

// 5) If we’re approving…
if ($action === 'approve') {
    // A) Mark proof approved
    $u = $conn->prepare("
      UPDATE payment_proofs
         SET status      = 'Approved',
             approved_at = NOW()
       WHERE id = ?
    ");
    $u->bind_param('i', $proofId);
    $u->execute();
    $u->close();

    if ($hasInvoice) {
        // B1) We already had a Pending invoice → recalc total + mark it Paid
        //   1) find student’s plan
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

        //   2) find how many were already Paid
        $c = $conn->prepare("
          SELECT COUNT(*) FROM payments
           WHERE student_id=? AND status='Paid'
        ");
        $c->bind_param('i',$studentId);
        $c->execute();
        $c->bind_result($paidCount);
        $c->fetch();
        $c->close();
        $isFirstPaid = ($paidCount===0);

        //   3) late?
        $today = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
        $isLate = ((int)$today->format('j') > 5);

        //   4) recompute fee
        $detail = calculate_student_fee(
          $conn,
          $studentId,
          $planId,
          $isFirstPaid,
          $isLate
        );

        //   5) respect skip flags
        $sk = $conn->prepare("
          SELECT skip_enroll_fee, skip_advance_fee
            FROM students
           WHERE id = ?
        ");
        $sk->bind_param('i',$studentId);
        $sk->execute();
        $sk->bind_result($skipEnroll,$skipAdvance);
        $sk->fetch();
        $sk->close();
        if ($skipEnroll)  $detail['enrollment_fee'] = 0.0;
        if ($skipAdvance) $detail['advance_fee']    = 0.0;

        $newTotal = (float)$detail['total'];

        //   6) mark invoice Paid
        $p = $conn->prepare("
          UPDATE payments
             SET status      = 'Paid',
                 amount_due  = ?,
                 amount_paid = ?,
                 paid_at     = NOW()
           WHERE payment_id = ?
        ");
        $p->bind_param('ddi',$newTotal,$newTotal,$paymentId);
        $p->execute();
        $p->close();

        $finalDue = $newTotal;
    } else {
        // B2) No Pending invoice exists → create one immediately as Paid
        $finalDue = $reportedAmount;

        $p = $conn->prepare("
          INSERT INTO payments
            (student_id, status, amount_due, amount_paid, paid_at)
          VALUES
            (?, 'Paid', 0.00, ?, NOW())
        ");
        $p->bind_param('id',$studentId,$reportedAmount);
        $p->execute();
        $p->close();
    }

    // C) Notify student
    create_notification(
      $conn,
      $studentId,
      'Payment Approved',
      sprintf(
        "Your payment proof #%d for ₹%.2f has been approved. Your total paid is ₹%.2f.",
        $proofId,
        $reportedAmount,
        $finalDue
      )
    );

    // D) Redirect back
    header('Location: /artovue/dashboard/admin/?page=admin_payment&msg=approved');
    exit;
}

// — Reject branch
if ($rejectionReason === '') {
    exit('Rejection reason is required.');
}

$r = $conn->prepare("
  UPDATE payment_proofs
     SET status           = 'Rejected',
         rejection_reason = ?
   WHERE id = ?
");
$r->bind_param('si',$rejectionReason,$proofId);
$r->execute();
$r->close();

// Notify student
create_notification(
  $conn,
  $studentId,
  'Payment Rejected',
  sprintf("Your payment proof #%d has been rejected. Reason: %s",
    $proofId,
    $rejectionReason
  )
);

// Redirect
header('Location: /artovue/dashboard/admin/?page=admin_payment&msg=rejected');
exit;
