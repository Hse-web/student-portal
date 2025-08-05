<?php
// File: actions/subscribe_plan.php

require_once __DIR__.'/../config/session.php';
require_role('admin');
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../includes/can_student_subscribe.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
  || ! verify_csrf_token($_POST['csrf_token'] ?? null)
) {
    http_response_code(403);
    exit('Invalid CSRF token');
}

$studentId = (int)($_POST['student_id'] ?? 0);
$planId    = (int)($_POST['plan_id']    ?? 0);
if (!$studentId || !$planId) {
    exit('Invalid input.');
}

// STEP 1: Validate using helper
$result = can_student_subscribe($conn, $studentId, $planId);
if (!$result['allowed']) {
    $_SESSION['flash_error'] = 'Subscription blocked: ' . $result['reason'];
    header('Location: ../dashboard/admin/index.php?page=students');
    exit;
}

$conn->begin_transaction();
try {
  // STEP 2: Count existing subscriptions (to determine if new student)
  $stmt = $conn->prepare("
    SELECT COUNT(*) FROM student_subscriptions
     WHERE student_id = ?
  ");
  $stmt->bind_param('i',$studentId);
  $stmt->execute();
  $stmt->bind_result($subsCount);
  $stmt->fetch();
  $stmt->close();

  // STEP 3: Create new subscription record
  $now = date('Y-m-d H:i:s');
  $stmt = $conn->prepare("
    INSERT INTO student_subscriptions
      (student_id, plan_id, subscribed_at)
    VALUES (?, ?, ?)
  ");
  $stmt->bind_param('iis', $studentId, $planId, $now);
  $stmt->execute();
  $stmt->close();

  // STEP 4: Fetch student flags and plan details for fee calculation
  $stmt = $conn->prepare("
    SELECT st.is_legacy, st.skip_enroll_fee, st.skip_advance_fee, st.pending_discount_percent,
           p.amount, p.duration_months, p.enrollment_fee, p.advance_fee, p.prorate_allowed, p.gst_percent
      FROM students st
      JOIN payment_plans p ON p.id = ?
     WHERE st.id = ?
     LIMIT 1
  ");
  $stmt->bind_param('ii', $planId, $studentId);
  $stmt->execute();
  $stmt->bind_result(
      $isLegacy, $skipEnroll, $skipAdvance, $refPct,
      $planAmt, $dur, $enrollFee, $advanceFee, $prorateAllowed, $gstPct
  );
  $stmt->fetch();
  $stmt->close();

  // STEP 5: Calculate one-time fees (enrollment, advance) for new non-legacy students
  $oneTime = 0.00;
  if (!$isLegacy && $subsCount === 0) {
      if (!$skipEnroll)  $oneTime += (float)$enrollFee;
      if (!$skipAdvance) $oneTime += (float)$advanceFee;
  }

  // STEP 6: Apply prorated discount if applicable (half fee after 15th for 1-month plans)
  if ($dur === 1 && $prorateAllowed) {
      $day = (int)date('j');
      if ($day > 15) {
          $planAmt = (float)$planAmt * 0.5;
      }
  }

  // STEP 7: Compute total amount due (GST applied, referral discount will be handled at payment time if applicable)
  $subtotal = (float)$planAmt + $oneTime;
  $gstAmt   = round($subtotal * ((float)$gstPct / 100), 2);
  $amountDue = round($subtotal + $gstAmt, 2);

  // STEP 8: Insert a pending payment record for this subscription
  $stmt = $conn->prepare("
    INSERT INTO payments
      (student_id, status, amount_paid, amount_due, paid_at)
    VALUES (?, 'Pending', 0.00, ?, NULL)
  ");
  $stmt->bind_param('id', $studentId, $amountDue);
  $stmt->execute();
  $stmt->close();

  $conn->commit();
  $_SESSION['flash_success'] = 'Subscription created successfully.';
  header('Location: ../dashboard/admin/index.php?page=students');
  exit;
}
catch(Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_error'] = 'Error: ' . $e->getMessage();
  header('Location: ../dashboard/admin/index.php?page=students');
  exit;
}
?>
