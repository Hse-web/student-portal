<?php
// File: actions/approve_payment_proof.php

require_once __DIR__ . '/../config/bootstrap.php';
require_role('admin');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../dashboard/includes/functions.php';    // create_notification()

$id  = (int)($_GET['id']  ?? 0);
$act = ( ($_GET['a'] ?? '') === 'approve' ) ? 'approve' : 'reject';

if ($id < 1) {
    exit('Invalid proof ID');
}

// Load the proof details
$stmt = $conn->prepare("
  SELECT student_id, amount
    FROM payment_proofs
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($studentId, $reportedAmount);
if (! $stmt->fetch()) {
    $stmt->close();
    exit('Proof not found');
}
$stmt->close();

if ($act === 'approve') {
    // Mark proof as approved
    $u = $conn->prepare("
      UPDATE payment_proofs
         SET status      = 'Approved',
             approved_at = NOW()
       WHERE id = ?
    ");
    $u->bind_param('i', $id);
    $u->execute();
    $u->close();

    // Find pending payment record
    $p = $conn->prepare("
      SELECT payment_id, amount_due
        FROM payments
       WHERE student_id = ?
         AND status = 'Pending'
       ORDER BY payment_id DESC
       LIMIT 1
    ");
    $p->bind_param('i', $studentId);
    $p->execute();
    $p->bind_result($payId, $pendingDue);
    if ($p->fetch()) {
        $p->close();
        // Update payment as paid
        $paidAmt = $reportedAmount;
        $newDue  = $reportedAmount;
        $p2 = $conn->prepare("
          UPDATE payments
             SET status='Paid',
                 amount_due=?,
                 amount_paid=?,
                 paid_at=NOW()
           WHERE payment_id=?
        ");
        $p2->bind_param('ddi', $newDue, $paidAmt, $payId);
        $p2->execute();
        $p2->close();
    } else {
        $p->close();
    }

    // If this was first payment, clear referral discount flag
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
    $isFirstPaid = ($paidCount === 1);
    if ($isFirstPaid) {
      $conn->query("UPDATE students SET pending_discount_percent = 0 WHERE id={$studentId}");
    }

    // Notify student of approval
    create_notification(
      $conn,
      [ $studentId ],
      'Payment Approved',
      sprintf(
        "Your payment proof #%d for ₹%.2f has been approved. Thank you!",
        $id, $reportedAmount
      )
    );
} else {
    // Mark proof as rejected
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

// Redirect back to admin payment list
header('Location: /artovue/dashboard/admin/?page=admin_payment');
exit;
?>
