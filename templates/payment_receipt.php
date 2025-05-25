<?php
// This file generates a payment receipt after the payment is confirmed
require_once __DIR__ . '/../config/session.php';
$studentId = $_SESSION['student_id'];
$paymentFile = '../data/payments.json';
$payments = file_exists($paymentFile) ? json_decode(file_get_contents($paymentFile), true) : [];

// Find the latest payment for the student
$payment = null;
foreach ($payments as $pay) {
    if ($pay['student_id'] == $studentId) {
        $payment = $pay;
        break;
    }
}

// Generate the receipt if the payment is found
if ($payment):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Receipt</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-5">
    <h2>Payment Receipt</h2>
    <p><strong>Student ID:</strong> <?php echo $payment['student_id']; ?></p>
    <p><strong>Transaction ID:</strong> <?php echo $payment['transaction_id']; ?></p>
    <p><strong>Amount Paid:</strong> ₹<?php echo $payment['amount']; ?></p>
    <p><strong>Payment Status:</strong> <?php echo $payment['status']; ?></p>
    <p><strong>Payment Date:</strong> <?php echo $payment['payment_date']; ?></p>

    <a href="../dashboard" class="btn btn-primary">Go back to Dashboard</a>
</body>
</html>
<?php endif; ?>
