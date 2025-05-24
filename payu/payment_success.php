<?php
// dashboard/payu/payment_success.php
require_once __DIR__ . '/../config/session.php';
require_role('student');
require_once __DIR__ . '/../config/db.php';

// You’ll get back a bunch of POST fields including ‘status’, ‘txnid’, etc.
$status   = $_POST['status']   ?? '';
$txnid    = $_POST['txnid']    ?? '';
$amount   = $_POST['amount']   ?? '';
// … verify the hash here if you like …

if ($status === 'success') {
   // 1) mark your payments & proofs in DB as Paid
   $studentId = (int)$_SESSION['student_id'];
   $stmt = $conn->prepare("
     UPDATE payments
        SET status = 'Paid',
            amount_paid = ?,
            amount_due  = 0,
            paid_at     = NOW()
      WHERE student_id = ? 
        AND status = 'Pending'
   ");
   $stmt->bind_param('di', $amount, $studentId);
   $stmt->execute();
   $stmt->close();

   // 2) record a payment_proof row
   $stmt = $conn->prepare("
     INSERT INTO payment_proofs
       (student_id, payment_method, txn_id, status, uploaded_at, approved_at)
     VALUES (?, 'PayU', ?, 'Approved', NOW(), NOW())
   ");
   $stmt->bind_param('is', $studentId, $txnid);
   $stmt->execute();
   $stmt->close();

   // 3) redirect back to your student_payment.php
   header("Location: ../student/student_payment.php");
   exit;
}

// if not success, fall through to failure
header("Location: payment_failure.php");
exit;
