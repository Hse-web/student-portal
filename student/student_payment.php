<?php
// File: dashboard/student/student_payment.php
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fee_calculator.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}

// 1) Determine if they've ever paid
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM payments
   WHERE student_id = ?
     AND status = 'Paid'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($paidCount);
$stmt->fetch();
$stmt->close();

$hasPaidBefore = ($paidCount > 0);

// 2) Load their latest subscription + plan info
$stmt = $conn->prepare("
  SELECT ss.plan_id, ss.subscribed_at,
         p.plan_name, p.duration_months
    FROM student_subscriptions ss
    JOIN payment_plans         p ON p.id = ss.plan_id
   WHERE ss.student_id = ?
   ORDER BY ss.subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($planId, $subscribedAt, $planName, $durationMonths);
if (! $stmt->fetch()) {
    echo '<p class="alert alert-danger">No active subscription found.</p>';
    exit;
}
$stmt->close();

// 3) If they’ve paid before, grab the most recent payment amount & date
$lastPaidAmt   = null;
$lastPaidAtRaw = null;
if ($hasPaidBefore) {
    $q = $conn->prepare("
      SELECT *
        FROM payments
       WHERE student_id = ?
         AND status     = 'Paid'
       ORDER BY paid_at DESC
       LIMIT 1
    ");
    $q->bind_param('i', $studentId);
    $q->execute();
    $res = $q->get_result();
    if ($row = $res->fetch_assoc()) {
        // Inspect $row to see which key holds your numeric amount.
        // Common candidates are 'paid_amount', 'amount_paid', 'total_paid', etc.
        foreach (['paid_amount','amount_paid','total_amount','amount'] as $col) {
            if (isset($row[$col])) {
                $lastPaidAmt = (float)$row[$col];
                break;
            }
        }
        $lastPaidAtRaw = $row['paid_at'] ?? $row['created_at'] ?? null;
    }
    $q->close();
}

// 4) Compute the full fee breakdown
$fee = calculate_student_fee(
    $conn,
    $studentId,
    $planId,
    /* isNewStudent */ ! $hasPaidBefore,
    /* isLate */ false
);

// 5) Load latest payment proof (with rejection_reason)
$stmt = $conn->prepare("
  SELECT status, rejection_reason
    FROM payment_proofs
   WHERE student_id = ?
   ORDER BY uploaded_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$proof = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

// 6) Fetch basic student info
$stmt = $conn->prepare("
  SELECT name,email,phone
    FROM students
   WHERE id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($stuName, $stuEmail, $stuPhone);
$stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Payment – Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" 
    rel="stylesheet"
  >
  <style>
    .fee-breakdown p { margin-bottom:.25rem; }
  </style>
</head>
<body class="bg-light">
  <div class="container py-4">

    <!-- Payment Overview Banner -->
    <div class="card mb-4">
      <div class="card-body text-center bg-info text-white">
        <h5><i class="bi bi-credit-card"></i> Payment Overview</h5>

        <?php if ($hasPaidBefore): 
          // round to nearest rupee, no decimals:
          $paidDisplay = number_format(round($lastPaidAmt), 0);
          $dueBase     = new DateTime($lastPaidAtRaw, new DateTimeZone('Asia/Kolkata'));
        ?>
          <p class="mb-1">
            You paid: 
            <strong>₹<?= $paidDisplay ?></strong> 
            on 
            <strong><?= $dueBase->format('M j, Y') ?></strong>
          </p>
        <?php else: 
          $dueBase = new DateTime($subscribedAt, new DateTimeZone('Asia/Kolkata'));
        ?>
          <p class="mb-1">
            Current Plan Fee: 
            <strong>₹<?= number_format($fee['total'],2) ?></strong>
          </p>
        <?php endif; ?>

        <small>
          Next Due: 
          <?php
            // bump by one plan cycle & force to 5th
            $dueBase
              ->modify("+{$durationMonths} months")
              ->setDate(
                (int)$dueBase->format('Y'),
                (int)$dueBase->format('m'),
                5
              );
            echo $dueBase->format('M j, Y');
          ?>
        </small>
      </div>
    </div>

    <?php if ($hasPaidBefore): ?>
      <!-- ALREADY PAID (Active) -->
      <div class="card mb-4 border-success">
        <div class="card-header">
          Current Plan: <?= htmlspecialchars($planName) ?>
          (<?= (int)$durationMonths ?>-Month)
        </div>
        <div class="card-body fee-breakdown">
          <h3 class="fw-bold">₹<?= number_format($fee['total'],2) ?></h3>
          <p>Base: ₹<?= number_format($fee['base_fee'],2) ?></p>
          <?php if ($fee['enrollment_fee']>0): ?>
            <p>+ Enrollment: ₹<?= number_format($fee['enrollment_fee'],2) ?></p>
          <?php endif; ?>
          <?php if ($fee['advance_fee']>0): ?>
            <p>+ Advance: ₹<?= number_format($fee['advance_fee'],2) ?></p>
          <?php endif; ?>
          <?php if ($fee['late_fee']>0): ?>
            <p>+ Late: ₹<?= number_format($fee['late_fee'],2) ?></p>
          <?php endif; ?>
          <p>+ GST <?= $fee['gst_percent'] ?>%: ₹<?= number_format($fee['gst_amount'],2) ?></p>

          <button class="btn btn-success" disabled>
            <i class="bi bi-check-circle"></i> Active
          </button>
          <a 
            href="student_invoice.php?student_id=<?= $studentId ?>" 
            class="btn btn-success ms-2"
          >
            <i class="bi bi-file-earmark-arrow-down"></i> Download Invoice
          </a>
        </div>
      </div>

    <?php else: ?>
      <!-- NOT YET PAID (Pay Now & Proof) -->
      <div class="card mb-4 border-primary">
        <div class="card-header">
          Current Plan: <?= htmlspecialchars($planName) ?>
          (<?= (int)$durationMonths ?>-Month)
        </div>
        <div class="card-body fee-breakdown">
          <h3 class="fw-bold">₹<?= number_format($fee['total'],2) ?></h3>
          <p>Base: ₹<?= number_format($fee['base_fee'],2) ?></p>
          <?php if ($fee['enrollment_fee']>0): ?>
            <p>+ Enrollment: ₹<?= number_format($fee['enrollment_fee'],2) ?></p>
          <?php endif; ?>
          <?php if ($fee['advance_fee']>0): ?>
            <p>+ Advance: ₹<?= number_format($fee['advance_fee'],2) ?></p>
          <?php endif; ?>
          <?php if ($fee['late_fee']>0): ?>
            <p>+ Late: ₹<?= number_format($fee['late_fee'],2) ?></p>
          <?php endif; ?>
          <p>+ GST <?= $fee['gst_percent'] ?>%: ₹<?= number_format($fee['gst_amount'],2) ?></p>

          <!-- Pay Now -->
          <form 
            method="post" 
            action="../../payu/payment_request.php" 
            class="d-inline"
          >
            <input type="hidden" name="student_id"  value="<?= $studentId ?>">
            <input type="hidden" name="plan_id"     value="<?= $planId ?>">
            <input type="hidden" name="amount"      value="<?= round($fee['total'],2) ?>">
            <input type="hidden" name="firstname"   value="<?= htmlspecialchars($stuName) ?>">
            <input type="hidden" name="email"       value="<?= htmlspecialchars($stuEmail) ?>">
            <input type="hidden" name="phone"       value="<?= htmlspecialchars($stuPhone) ?>">
            <input type="hidden" name="productinfo" value="<?= htmlspecialchars($planName) ?>">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-cart"></i> Pay Now
            </button>
          </form>

          <!-- Proof Uploader / Status -->
          <div class="mt-4 text-center">
            <?php if (empty($proof)): ?>
              <a href="upload_payment_proof.php" class="btn btn-lg btn-primary">
                <i class="bi bi-upload"></i> Upload Payment Proof
              </a>

            <?php elseif ($proof['status'] === 'Pending'): ?>
              <span class="badge bg-warning">Proof Pending</span>

            <?php elseif ($proof['status'] === 'Rejected'): ?>
              <div class="mb-2">
                <span class="badge bg-danger">Proof Rejected</span><br>
                <small class="text-danger">
                  Reason: <?= htmlspecialchars($proof['rejection_reason'] ?? '—') ?>
                </small>
              </div>
              <a href="upload_payment_proof.php" class="btn btn-danger">
                <i class="bi bi-arrow-repeat"></i> Re-upload Proof
              </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

  </div><!-- /.container -->
  <script 
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
