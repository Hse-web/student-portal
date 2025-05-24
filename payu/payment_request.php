<?php
// dashboard/payu/payment_request.php
require_once __DIR__ . '/../config/session.php';
require_role('student');

$config = include __DIR__ . '/config.php';
require_once __DIR__ . '/generate_hash.php';   // ← include our new helper

// 1) Gather all POSTed data
$studentId   = (int) $_POST['student_id'];
$planId      = (int) $_POST['plan_id'];
$amount      = (float) $_POST['amount'];
$firstname   = trim($_POST['firstname']);
$email       = trim($_POST['email']);
$phone       = trim($_POST['phone']);
$productinfo = trim($_POST['productinfo']);

// 2) Txn ID
$txnid = 'TXN' . time();

// 3) Generate the hash locally
$hash = generatePayuHash(
  $txnid,
  $amount,
  $productinfo,
  $firstname,
  $email
);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Redirecting to PayU…</title>
</head>
<body onload="document.forms.payuForm.submit()">
  <form action="<?= htmlspecialchars($config['base_url']) ?>" method="post" name="payuForm">
    <input type="hidden" name="key"         value="<?= htmlspecialchars($config['key']) ?>" />
    <input type="hidden" name="txnid"       value="<?= htmlspecialchars($txnid) ?>" />
    <input type="hidden" name="amount"      value="<?= htmlspecialchars($amount) ?>" />
    <input type="hidden" name="productinfo" value="<?= htmlspecialchars($productinfo) ?>" />
    <input type="hidden" name="firstname"   value="<?= htmlspecialchars($firstname) ?>" />
    <input type="hidden" name="email"       value="<?= htmlspecialchars($email) ?>" />
    <input type="hidden" name="phone"       value="<?= htmlspecialchars($phone) ?>" />
    <input type="hidden" name="surl"        value="<?= htmlspecialchars($config['success_url']) ?>" />
    <input type="hidden" name="furl"        value="<?= htmlspecialchars($config['failure_url']) ?>" />
    <input type="hidden" name="hash"        value="<?= htmlspecialchars($hash) ?>" />
    <!-- udf1–udf10 are intentionally omitted (they default to empty) -->
  </form>
  <p>Redirecting to PayU… if nothing happens, <button onclick="document.forms.payuForm.submit()">click here</button>.</p>
</body>
</html>
