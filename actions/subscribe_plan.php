<?php
// File: actions/subscribe_plan.php

require_once __DIR__ . '/../config/session.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$studentId = (int)($_POST['student_id'] ?? 0);
$planId    = (int)($_POST['plan_id']    ?? 0);
if (!$studentId || !$planId) exit('Invalid input.');

$conn->begin_transaction();
try {
  // 1) Count existing subscriptions
  $stmt = $conn->prepare("
    SELECT COUNT(*) FROM student_subscriptions
     WHERE student_id = ?
  ");
  $stmt->bind_param('i',$studentId);
  $stmt->execute();
  $stmt->bind_result($subsCount);
  $stmt->fetch();
  $stmt->close();

  // 2) Create subscription
  $now = date('Y-m-d H:i:s');
  $stmt = $conn->prepare("
    INSERT INTO student_subscriptions
      (student_id,plan_id,subscribed_at)
    VALUES (?,?,?)
  ");
  $stmt->bind_param('iis',$studentId,$planId,$now);
  $stmt->execute();
  $stmt->close();

  // 3) Fetch centre fees & legacy flag & plan details
  $stmt = $conn->prepare("
    SELECT 
      cfs.enrollment_fee,
      cfs.advance_fee,
      cfs.prorate_allowed,
      cfs.gst_percent,
      st.is_legacy,
      p.amount        AS plan_amt,
      p.duration_months,
      sub.subscribed_at
    FROM student_subscriptions sub
    JOIN payment_plans       p   ON p.id   = sub.plan_id
    JOIN center_fee_settings cfs ON cfs.centre_id = (
      SELECT centre_id FROM students WHERE id=?
    )
    JOIN students            st  ON st.id = sub.student_id
   WHERE sub.student_id = ?
   ORDER BY sub.subscribed_at DESC
   LIMIT 1
  ");
  $stmt->bind_param('ii',$studentId,$studentId);
  $stmt->execute();
  $stmt->bind_result(
    $enrollFee,$advanceFee,
    $prorateAllowed,$gstPct,
    $isLegacy,$planAmt,
    $dur,$subAt
  );
  $stmt->fetch();
  $stmt->close();

  // 4) Decide one-time fees: only brand-new & non-legacy
  $oneTime = (!$isLegacy && $subsCount===0)
    ? ($enrollFee + $advanceFee)
    : 0;

  // 5) Prorate tuition if needed
  if ($dur===1 && $prorateAllowed && $subAt) {
    $day=(int)(new DateTime($subAt))->format('j');
    if ($day>15) $planAmt *= 0.5;
  }

  // 6) Compute total due = tuition + oneTime + GST
  $subtotal  = $planAmt + $oneTime;
  $gstAmt    = round($subtotal * ($gstPct/100),2);
  $amountDue = round($subtotal + $gstAmt,2);

  // 7) Insert payment row
  $stmt = $conn->prepare("
    INSERT INTO payments
      (student_id,status,amount_paid,amount_due,paid_at)
    VALUES (?, 'Pending', 0.00, ?, NULL)
  ");
  $stmt->bind_param('id',$studentId,$amountDue);
  $stmt->execute();
  $stmt->close();

  $conn->commit();
  $_SESSION['flash_success']='Subscription created.';
  header('Location: ../dashboard/admin/index.php?page=students');
  exit;
}
catch(Throwable $e){
  $conn->rollback();
  $_SESSION['flash_error']='Error: '.$e->getMessage();
  header('Location: ../dashboard/admin/index.php?page=students');
  exit;
}
