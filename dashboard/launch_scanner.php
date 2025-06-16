<?php
// dashboard/launch_scanner.php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
if (empty($_SESSION['logged_in'])||$_SESSION['role']!=='student') {
  header('Location: ../login/index.php'); exit();
}
require __DIR__.'/../config/db.php';
$studentId = (int)$_SESSION['user_id'];
// (optional) fetch your center’s UPI / QR URL from centre_fee_settings
$qrUrl = 'upi://pay?...';
?>
<!DOCTYPE html>
<html><head><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Scan & Pay</title>
</head><body style="text-align:center;font-family:sans-serif">
  <h3>Scan to Pay</h3>
  <img src="https://api.qrserver.com/v1/create-qr-code/?data=<?=urlencode($qrUrl)?>&size=200x200">
  <p>After you pay, come back and upload your payment proof below.</p>
  <a href="../action/upload_proof.php" class="btn btn-primary">Upload Proof</a>
</body></html>
