<?php
// dashboard/payu/payment_failure.php
require_once __DIR__ . '/../config/session.php';
require_role('student');
require_once __DIR__ . '/../config/db.php';

// fetch the student’s latest pending amount
$studentId = (int)$_SESSION['student_id'];
$stmt = $conn->prepare("
  SELECT amount_due
    FROM payments
   WHERE student_id = ?
     AND status = 'Pending'
   ORDER BY payment_id DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($due);
if (!$stmt->fetch()) {
  $due = 0;
}
$stmt->close();
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Payment Failed</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-light">
<div class="container py-5">
  <div class="alert alert-danger">
    <h4 class="alert-heading">Payment Failed</h4>
    <p>Unfortunately your payment did not go through.</p>
    <hr>
    <p>Your current outstanding due is ₹<?= number_format($due,0) ?>.</p>
    <a href="../dashboard/student/student_payment.php" class="btn btn-primary">Retry / Upload Proof</a>
  </div>
</div>
</body></html>
