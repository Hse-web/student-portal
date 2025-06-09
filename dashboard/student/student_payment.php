<?php
// File: dashboard/student/student_payment.php

$page = 'student_payment';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fee_calculator.php';
require_once __DIR__ . '/../includes/can_student_subscribe.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}

// ─── 1) Count how many “Paid” rows exist for this student ──────────
$stmtA = $conn->prepare("
  SELECT COUNT(*) 
    FROM payments
   WHERE student_id = ?
     AND status = 'Paid'
");
$stmtA->bind_param('i', $studentId);
$stmtA->execute();
$stmtA->bind_result($paidCount);
$stmtA->fetch();
$stmtA->close();

// If $paidCount > 0, the student has already paid at least one invoice
$isNewStudent = ($paidCount === 0);

// ─── 2) Compute current due & next due date ────────────────────────
list($totalDue, $nextDueDate) = compute_student_due($conn, $studentId);

// ─── 2a) Determine if “late fee” should apply (today’s day > 5) ────
// This replaces “overdue” logic—late fee kicks in after day 5
$isLate = ((int) date('j') > 5);

// ─── 2b) Also track “overdue” strictly for deciding the overdue card display
// (student is considered “overdue” if today is on or past the next due date)
$isOverdue = false;
if (strtotime($nextDueDate) !== false) {
    $isOverdue = (strtotime($nextDueDate) <= time());
}

// ─── 3) Fetch the student’s latest subscription + plan + centre/group ─
$stmtB = $conn->prepare("
  SELECT s.plan_id, p.plan_name, p.amount AS base_amount, p.duration_months,
         st.centre_id, st.group_name
    FROM student_subscriptions s
    JOIN payment_plans         p ON p.id = s.plan_id
    JOIN students             st ON st.id = s.student_id
   WHERE s.student_id = ?
   ORDER BY s.subscribed_at DESC
   LIMIT 1
");
$stmtB->bind_param('i', $studentId);
$stmtB->execute();
$stmtB->bind_result($currPlanId, $currPlanName, $currAmt, $currDuration, $centreId, $studentGroup);
$stmtB->fetch();
$stmtB->close();

// ─── 4) Calculate the fee breakdown, passing $isNewStudent & $isLate ─
$currFee = calculate_student_fee(
    $conn,
    $studentId,
    $currPlanId,
    $isNewStudent,
    $isLate
);

// ─── 5) (Optional) Fetch all plans for this student’s group (for a “Change Plan” dropdown) ─
$stmtC = $conn->prepare("
  SELECT id, duration_months, amount
    FROM payment_plans
   WHERE centre_id = ?
     AND group_name = ?
   ORDER BY duration_months
");
$stmtC->bind_param('is', $centreId, $studentGroup);
$stmtC->execute();
$allPlans = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtC->close();

// ─── 6) (Optional) Fetch the latest payment proof, if you still want to show its badge-status ─
$stmtD = $conn->prepare("
  SELECT status
    FROM payment_proofs
   WHERE student_id = ?
   ORDER BY uploaded_at DESC
   LIMIT 1
");
$stmtD->bind_param('i', $studentId);
$stmtD->execute();
$proof = $stmtD->get_result()->fetch_assoc();
$stmtD->close();

// ─── 7) Fetch basic student info + pending_discount_percent ────────
$stmtE = $conn->prepare("
  SELECT name, email, phone, pending_discount_percent
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmtE->bind_param('i', $studentId);
$stmtE->execute();
$stmtE->bind_result($stuName, $stuEmail, $stuPhone, $pendingDisc);
$stmtE->fetch();
$stmtE->close();
?>

<div class="container py-4">

  <!-- Referral Discount Banner (if any) -->
  <?php if ($pendingDisc > 0): ?>
    <div class="alert alert-success">
      🎉 You’ve earned a <?= round($pendingDisc) ?>% discount on your next payment!
    </div>
  <?php endif; ?>

  <!-- Payment Overview Header -->
  <div class="card mb-4">
    <div class="card-body text-center bg-info text-white">
      <h5><i class="bi bi-credit-card"></i> Payment Overview</h5>
      <p class="mb-1">
        Current Plan Fee:
        <strong>₹<?= round($currFee['total']) ?></strong>
      </p>

      <?php if ($paidCount === 0): ?>
        <p class="mb-1 text-warning">
          Pending Due:
          <strong>₹<?= round($currFee['total']) ?></strong>
        </p>
      <?php endif; ?>

      <small>Next Due: <?= htmlspecialchars($nextDueDate) ?></small>
    </div>
  </div>

  <?php if ($paidCount > 0): ?>
    <!-- ─── ALREADY PAID (Active) ────────────────────────────────────── -->
    <div class="card mb-4 border-primary">
      <div class="card-header">
        Current Plan: <?= htmlspecialchars($currPlanName) ?> (<?= $currDuration ?>-Month)
      </div>
      <div class="card-body">
        <h3 class="fw-bold">₹<?= round($currFee['total']) ?></h3>
        <p>Base: ₹<?= round($currFee['base_fee']) ?></p>
        <?php if ($currFee['enrollment_fee'] > 0): ?>
          <p>+ Enrollment: ₹<?= round($currFee['enrollment_fee']) ?></p>
        <?php endif; ?>
        <?php if ($currFee['advance_fee'] > 0): ?>
          <p>+ Advance: ₹<?= round($currFee['advance_fee']) ?></p>
        <?php endif; ?>
        <?php if ($currFee['late_fee'] > 0): ?>
          <p>+ Late: ₹<?= round($currFee['late_fee']) ?></p>
        <?php endif; ?>
        <p>+ GST <?= $currFee['gst_percent'] ?>%: ₹<?= round($currFee['gst_amount']) ?></p>

        <!-- “Active” badge shown now that $paidCount > 0 -->
        <button class="btn btn-success" disabled>
          <i class="bi bi-check-circle"></i> Active
        </button>

        <!-- Download Invoice (since they have paid) -->
        <a
          href="student_invoice.php?student_id=<?= $studentId ?>"
          class="btn btn-success ms-2"
        >
          <i class="bi bi-file-earmark-arrow-down"></i> Download Invoice
        </a>
      </div>
    </div>

  <?php else: ?>
    <!-- ─── NOT YET PAID SECTION ─────────────────────────────────────── -->
    <div class="card mb-4 border-primary">
      <div class="card-header">
        Current Plan: <?= htmlspecialchars($currPlanName) ?> (<?= $currDuration ?>-Month)
      </div>
      <div class="card-body">
        <h3 class="fw-bold">₹<?= round($currFee['total']) ?></h3>
        <p>Base: ₹<?= round($currFee['base_fee']) ?></p>
        <?php if ($currFee['enrollment_fee'] > 0): ?>
          <p>+ Enrollment: ₹<?= round($currFee['enrollment_fee']) ?></p>
        <?php endif; ?>
        <?php if ($currFee['advance_fee'] > 0): ?>
          <p>+ Advance: ₹<?= round($currFee['advance_fee']) ?></p>
        <?php endif; ?>
        <?php if ($currFee['late_fee'] > 0): ?>
          <p>+ Late: ₹<?= round($currFee['late_fee']) ?></p>
        <?php endif; ?>
        <p>+ GST <?= $currFee['gst_percent'] ?>%: ₹<?= round($currFee['gst_amount']) ?></p>

        <!-- Pay Now Button (via PayU) -->
        <form
          method="post"
          action="../../payu/payment_request.php"
          class="d-inline"
        >
          <input type="hidden" name="student_id"  value="<?= $studentId ?>">
          <input type="hidden" name="plan_id"     value="<?= $currPlanId ?>">
          <input type="hidden" name="amount"      value="<?= round($currFee['total']) ?>">
          <input type="hidden" name="firstname"   value="<?= htmlspecialchars($stuName) ?>">
          <input type="hidden" name="email"       value="<?= htmlspecialchars($stuEmail) ?>">
          <input type="hidden" name="phone"       value="<?= htmlspecialchars($stuPhone) ?>">
          <input type="hidden" name="productinfo" value="<?= htmlspecialchars($currPlanName) ?>">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-cart"></i> Pay Now
          </button>
        </form>

        <!-- Upload Payment Proof Button (if proof not yet uploaded) -->
        <div class="mt-4 text-center">
          <?php if (empty($proof)): ?>
            <a
              href="upload_payment_proof.php"
              class="btn btn-lg btn-primary"
            >
              <i class="bi bi-upload"></i> Upload Payment Proof
            </a>

          <?php elseif ($proof['status'] === 'Pending'): ?>
            <span class="badge bg-warning">Proof Pending</span>

          <?php elseif ($proof['status'] === 'Rejected'): ?>
            <span class="badge bg-danger">Proof Rejected</span>
            <a
              href="upload_payment_proof.php"
              class="btn btn-danger ms-2"
            >
              <i class="bi bi-arrow-repeat"></i> Re-upload
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <?php endif; ?>

  <!-- ─── OVERDUE CARD (only if still unpaid and past due date) ─────────────────── -->
  <?php if ($paidCount === 0 && $isOverdue): ?>
    <div class="card mb-4 border-warning">
      <div class="card-header">
        Renew <?= htmlspecialchars($currPlanName) ?> (<?= $currDuration ?>-Month)
      </div>
      <div class="card-body">
        <h3 class="fw-bold">₹<?= round($currFee['total']) ?></h3>
        <p>Base: ₹<?= round($currFee['base_fee']) ?></p>
        <?php if ($currFee['enrollment_fee'] > 0): ?>
          <p>+ Enrollment: ₹<?= round($currFee['enrollment_fee']) ?></p>
        <?php endif; ?>
        <?php if ($currFee['advance_fee'] > 0): ?>
          <p>+ Advance: ₹<?= round($currFee['advance_fee']) ?></p>
        <?php endif; ?>
        <?php if ($currFee['late_fee'] > 0): ?>
          <p>+ Late: ₹<?= round($currFee['late_fee']) ?></p>
        <?php endif; ?>
        <p>+ GST <?= $currFee['gst_percent'] ?>%: ₹<?= round($currFee['gst_amount']) ?></p>

        <!-- Upload Payment Proof (Overdue) -->
        <a
          href="upload_payment_proof.php"
          class="btn btn-secondary me-2"
        >
          <i class="bi bi-upload"></i> Upload Payment Proof
        </a>

        <!-- PayU Payment Form (Overdue) -->
        <?php
        // Replace with your real PayU key / salt / URLs
        $payuKey     = '';
        $payuSalt    = '';
        $payuUrl     = 'https://test.payu.in/_payment';
        $txnid       = 'TXN' . time();
        $amount      = round($currFee['total']);
        $firstName   = htmlspecialchars($stuName);
        $email       = htmlspecialchars($stuEmail);
        $phone       = htmlspecialchars($stuPhone);
        $productinfo = htmlspecialchars($currPlanName);
        $hashString  = "$payuKey|$txnid|$amount|$productinfo|$firstName|$email|||||||||||$payuSalt";
        $hash        = strtolower(hash('sha512', $hashString));
        ?>
        <form
          action="<?= $payuUrl ?>"
          method="post"
          class="d-inline ms-2"
        >
          <input type="hidden" name="key"         value="<?= $payuKey ?>">
          <input type="hidden" name="txnid"       value="<?= $txnid ?>">
          <input type="hidden" name="amount"      value="<?= $amount ?>">
          <input type="hidden" name="productinfo" value="<?= $productinfo ?>">
          <input type="hidden" name="firstname"   value="<?= $firstName ?>">
          <input type="hidden" name="email"       value="<?= $email ?>">
          <input type="hidden" name="phone"       value="<?= $phone ?>">
          <input type="hidden" name="surl"        value="../payu/payment_success.php">
          <input type="hidden" name="furl"        value="../payu/payment_failure.php">
          <input type="hidden" name="hash"        value="<?= $hash ?>">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-cart"></i> Pay Now with PayU
          </button>
        </form>
      </div>
    </div>
  <?php endif; ?>

</div>
