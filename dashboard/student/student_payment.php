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

// ─── 1) Is this student new? ─────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) FROM student_subscriptions WHERE student_id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($subCount);
$stmt->fetch();
$stmt->close();
$isNewStudent = ($subCount === 0);

// ─── 2) Compute current due & next due date ─────────────────
list($totalDue, $nextDueDate) = compute_student_due($conn, $studentId);
$isOverdue = strtotime($nextDueDate) <= time();

// ─── 3) Fetch latest subscription + plan info ───────────────
$stmt = $conn->prepare("
  SELECT p.id, p.plan_name, p.amount, p.duration_months, s.subscribed_at
    FROM student_subscriptions s
    JOIN payment_plans         p ON p.id = s.plan_id
   WHERE s.student_id = ?
   ORDER BY s.subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($currPlanId, $currPlanName, $currAmt, $currDuration, $subscribedAt);
$stmt->fetch();
$stmt->close();

// ─── 4) Calculate the fee breakdown ──────────────────────────
$currFee = calculate_student_fee(
    $conn,
    $studentId,
    $currPlanId,
    $isNewStudent,
    $isOverdue
);

// ─── Fetch ALL plans FOR this student’s group ───────────────────────
$stmt = $conn->prepare("
  SELECT id, duration_months, amount
    FROM payment_plans
   WHERE centre_id = ?
     AND group_name = ?
   ORDER BY duration_months
");
$stmt->bind_param('is',$centreId,$studentGroup);
$stmt->execute();
$allPlans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Fetch latest proof (if any) ──────────────────────────────────
$stmt = $conn->prepare("
  SELECT status
    FROM payment_proofs
   WHERE student_id=?
   ORDER BY uploaded_at DESC
   LIMIT 1
");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$proof = $stmt->get_result()->fetch_assoc();
$stmt->close();
// ─── 6) Fetch student info + pending_discount_percent ──────
$stmt = $conn->prepare("
  SELECT name, email, phone, pending_discount_percent
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($stuName, $stuEmail, $stuPhone, $pendingDisc);
$stmt->fetch();
$stmt->close();
?>

<div class="container py-4">

  <!-- Referral Discount Banner -->
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
      <?php if (empty($proof) || $proof['status'] !== 'Approved'): ?>
        <p class="mb-1 text-warning">
          Pending Due:
          <strong>₹<?= round($currFee['total']) ?></strong>
        </p>
      <?php endif; ?>
      <small>Next Due: <?= htmlspecialchars($nextDueDate) ?></small>
    </div>
  </div>

  <?php if (! $isOverdue): ?>
    <!-- ─── Active (Not Overdue) Section ─────────────────────────── -->
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

        <!-- “Active” Badge -->
        <button class="btn btn-success" disabled>
          <i class="bi bi-check-circle"></i> Active
        </button>

        <?php if (empty($proof) || $proof['status'] !== 'Approved'): ?>
          <!-- ─── Pay Now Form (via PayU) ─────────────────────────── -->
          <form
            method="post"
            action="../../payu/payment_request.php"
            class="d-inline ms-2"
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

          <!-- ─── Upload Payment Proof (if not yet uploaded) ─────── -->
          <div class="text-center mt-4">
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
            <?php else: /* status === 'Approved' */ ?>
              <span class="badge bg-success">Proof Approved</span>
              <a
                href="student_invoice.php?student_id=<?= $studentId ?>"
                class="btn btn-success ms-2"
              >
                <i class="bi bi-file-earmark-arrow-down"></i> Download Invoice
              </a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <!-- ─── Overdue (Expired) Section ───────────────────────────────── -->
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

        <!-- ─── Upload Payment Proof (Overdue) ───────────────────── -->
        <a
          href="upload_payment_proof.php"
          class="btn btn-secondary me-2"
        >
          <i class="bi bi-upload"></i> Upload Payment Proof
        </a>

        <!-- ─── PayU Payment Form (Overdue) ──────────────────────── -->
        <?php
        // Note: Replace these with your real PayU key / salt / URLs
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
          class="d-inline"
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
