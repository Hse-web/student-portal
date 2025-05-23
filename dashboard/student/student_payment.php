<?php
require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fee_calculator.php';
require_once __DIR__ . '/../includes/can_student_subscribe.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
$stmt = $conn->prepare("SELECT name, group_name FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName, $studentGroup);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM student_subscriptions WHERE student_id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($subCount);
$stmt->fetch();
$stmt->close();
$isNewStudent = ($subCount === 0);

list($totalDue, $nextDue) = compute_student_due($conn, $studentId);
$isOverdue = strtotime($nextDue) <= strtotime(date('Y-m-d'));

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Get active subscription
$stmt = $conn->prepare("
  SELECT p.id, p.amount, p.duration_months, s.subscribed_at
  FROM student_subscriptions s
  JOIN payment_plans p ON p.id = s.plan_id
  WHERE s.student_id = ?
  ORDER BY s.subscribed_at DESC
  LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($currPlanId, $currAmt, $currDuration, $subscribedAt);
$stmt->fetch();
$stmt->close();

// Calculate fee of active plan
$currFee = calculate_student_fee($conn, $studentId, $currPlanId, false, $isOverdue);

// Determine next due date properly
$subDate = new DateTime($subscribedAt);
$subDate->modify("+{$currDuration} months")->setDate((int)$subDate->format('Y'), (int)$subDate->format('m'), 5);
$nextDueDate = $subDate->format('M j, Y');

$stmt = $conn->prepare("SELECT status FROM payment_proofs WHERE student_id = ? ORDER BY uploaded_at DESC LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$proof = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
  SELECT id, duration_months FROM payment_plans
   WHERE centre_id = (SELECT centre_id FROM students WHERE id = ?)
     AND LOWER(group_name) = LOWER(?)
   ORDER BY duration_months");
$stmt->bind_param('is', $studentId, $studentGroup);
$stmt->execute();
$allPlans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Payment Overview</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#f0f2f5; }
    .banner {
      background: #cfe2ff;
      padding: 1rem;
      border-radius: .5rem;
      margin-bottom: 2rem;
      text-align: center;
    }
    .plan-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 1rem;
    }
    .plan-card {
      background: #fff;
      border-radius: 1rem;
      padding: 1.5rem;
      text-align: center;
      transition: transform .2s;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    .plan-card:hover { transform: translateY(-5px); }
    .plan-title  { font-size: 1.2rem; font-weight: 600; }
    .plan-price  { font-size: 1.75rem; margin: .5rem 0; }
    .gst-note    { font-size: 0.9rem; color: #666; }
    .btn-plan    { width: 100%; margin-top: .75rem; }
    .current     { background: #0d6efd; color: #fff; border: none; }
    .upgrade     { border: 2px solid #0d6efd; color: #0d6efd; }
    .upgrade:hover { background: #0d6efd; color: #fff; }
    .current-card { border: 2px solid #0d6efd; background-color: #e9f3ff; }
  </style>
</head>
<body>
<div class="container py-4">
  <h4 class="text-center text-danger mb-4">
    <i class="bi bi-credit-card"></i> Payment Overview
  </h4>

  <div class="banner">
    <strong>Current Plan Fee:</strong>
    ₹<?= number_format($currFee['total'], 0) ?><br>

    <?php if (empty($proof) || $proof['status'] !== 'Approved'): ?>
      <strong class="text-danger">Pending Due (This Cycle):</strong>
      ₹<?= number_format($currFee['total'], 0) ?><br>
    <?php endif; ?>

    <div class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-muted' ?>">
      Next Due: <?= htmlspecialchars($nextDueDate) ?>
    </div>
  </div>

  <div class="plan-grid mb-4">
    <?php foreach ($allPlans as $pl):
      $fee = calculate_student_fee($conn, $studentId, $pl['id'], $isNewStudent, $isOverdue);
      $total = $fee['total'];
      $isCurr = ($pl['id'] == $currPlanId);
      $label = match($pl['duration_months']) {
        1 => 'Regular Works',
        2,3 => 'Core Works',
        6 => 'Pro Works',
        default => "{$pl['duration_months']}-Month Plan"
      };
      $subStatus = can_student_subscribe($conn, $studentId, $pl['id']);
      $isAllowed = $subStatus['allowed'];
    ?>
      <div class="plan-card <?= $isCurr ? 'current-card' : '' ?>">
        <div class="plan-title"><?= htmlspecialchars($label) ?></div>
        <div class="plan-price">₹<?= number_format($total, 2) ?></div>
        <div class="gst-note">
          Base: ₹<?= number_format($fee['base_fee'], 0) ?><br>
          + Enroll: ₹<?= number_format($fee['enrollment_fee'], 0) ?><br>
          + Advance: ₹<?= number_format($fee['advance_fee'], 0) ?><br>
          + Late: ₹<?= number_format($fee['late_fee'], 0) ?><br>
          + GST (<?= $fee['gst_percent'] ?>%): ₹<?= number_format($fee['gst_amount'], 0) ?>
        </div>
        <?php if ($isCurr): ?>
          <a href="#" class="btn btn-plan current" disabled>
            <i class="bi bi-check-circle"></i> Current Plan
          </a>
          <?php if ($proof && $proof['status'] === 'Approved' && strtotime($nextDueDate) > time()): ?>
            <a href="student_invoice.php?student_id=<?= $studentId ?>" class="btn btn-outline-secondary btn-sm mt-2">
              <i class="bi bi-file-earmark-arrow-down"></i> Download Invoice
            </a>
          <?php elseif (!$proof || $proof['status'] !== 'Approved'): ?>
            <a href="upload_payment_proof.php" class="btn btn-outline-primary btn-sm mt-2">
              <i class="bi bi-upload"></i> Upload Payment Proof
            </a>
          <?php endif; ?>
        <?php elseif (!$isAllowed): ?>
          <div class="text-danger small mt-2"> <?= htmlspecialchars($subStatus['reason']) ?> </div>
        <?php else: ?>
          <a href="<?= 'https://wa.me/919876543210?text=' . urlencode("Hi, I’m {$studentName} and I’d like the {$label} plan.") ?>"
             class="btn btn-plan upgrade">
            <i class="bi bi-whatsapp"></i> Select
          </a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="text-center">
      <?php if (empty($proof)): ?>
        <a href="upload_payment_proof.php" class="btn btn-lg btn-primary">
          <i class="bi bi-upload"></i> Upload Payment Proof
        </a>
      <?php elseif ($proof['status'] === 'Pending'): ?>
        <span class="badge bg-warning">Proof Pending</span>
      <?php elseif ($proof['status'] === 'Rejected'): ?>
        <span class="badge bg-danger">Proof Rejected</span>
        <a href="upload_payment_proof.php" class="btn btn-danger ms-2">
          <i class="bi bi-arrow-repeat"></i> Re-upload
        </a>
      <?php else: ?>
        <span class="badge bg-success">Proof Approved</span>
        <a href="student_invoice.php?student_id=<?= $studentId ?>" class="btn btn-success ms-2">
          <i class="bi bi-file-earmark-arrow-down"></i> Download Invoice
        </a>
      <?php endif; ?>
    </div>
</body>
</html>
