<?php
require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// 1) Load student + group info
$studentId = (int)($_SESSION['student_id'] ?? 0);
$stmt = $conn->prepare("SELECT name, group_name FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName, $studentGroup);
$stmt->fetch();
$stmt->close();

// 2) Get due & next due
list($totalDue, $nextDue) = compute_student_due($conn, $studentId);
$isOverdue = strtotime($nextDue) <= strtotime(date('Y-m-d'));

// 3) Flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// 4) Current plan amount
$stmt = $conn->prepare("
  SELECT p.amount
    FROM student_subscriptions s
    JOIN payment_plans p ON p.id = s.plan_id
   WHERE s.student_id = ?
   ORDER BY s.subscribed_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($currAmt);
$stmt->fetch();
$stmt->close();

// 5) GST %
$stmt = $conn->prepare("
  SELECT c.gst_percent
    FROM students s
    JOIN center_fee_settings c ON c.centre_id = s.centre_id
   WHERE s.id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($gstPct);
$stmt->fetch();
$stmt->close();

// 6) Plans available to student
$stmt = $conn->prepare("
  SELECT id, duration_months, amount
    FROM payment_plans
   WHERE centre_id = (SELECT centre_id FROM students WHERE id = ?)
     AND group_name = ?
   ORDER BY duration_months
");
$stmt->bind_param('is', $studentId, $studentGroup);
$stmt->execute();
$allPlans = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 7) Latest proof status
$stmt = $conn->prepare("
  SELECT status
    FROM payment_proofs
   WHERE student_id = ?
   ORDER BY uploaded_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$proof = $stmt->get_result()->fetch_assoc();
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
  </style>
</head>
<body>
  <div class="container py-4">
    <h4 class="text-center text-danger mb-4">
      <i class="bi bi-credit-card"></i> Payment Overview
    </h4>

    <!-- Flash -->
    <?php if ($flash): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

    <!-- Banner -->
    <?php if (empty($proof) || $proof['status'] !== 'Approved'): ?>
      <div class="banner">
        <strong>Amount to Pay:</strong>
        <span class="fs-4">₹<?= number_format($totalDue, 0) ?></span><br>
        <div class="<?= $isOverdue ? 'text-danger fw-bold' : 'text-muted' ?>">
          Next Due: <?= htmlspecialchars($nextDue) ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Plan Grid -->
    <div class="plan-grid mb-4">
      <?php foreach ($allPlans as $pl):
        $base  = $pl['amount'];
        $gst   = round($base * $gstPct / 100);
        $total = $base + $gst;
        $label = match($pl['duration_months']) {
          1 => 'Regular Works',
          2,3 => 'Core Works',
          6 => 'Pro Works',
          default => "{$pl['duration_months']}-Month Plan"
        };
        $isCurr = $base === $currAmt;
      ?>
        <div class="plan-card">
          <div class="plan-title"><?= htmlspecialchars($label) ?></div>
          <div class="plan-price">₹<?= number_format($total, 2) ?></div>
          <div class="gst-note">₹<?= number_format($base, 0) ?> + ₹<?= number_format($gst, 0) ?> GST</div>
          <a href="<?= $isCurr
                       ? '#'
                       : 'https://wa.me/919876543210?text='
                         . urlencode("Hi, I’m {$studentName} and I’d like the {$label} plan.") ?>"
             class="btn btn-plan <?= $isCurr ? 'current' : 'upgrade' ?>"
             <?= $isCurr ? 'disabled' : '' ?>>
            <i class="bi bi-whatsapp"></i> <?= $isCurr ? 'Current' : 'Select' ?>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Upload/Download Section -->
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
  </div>
</body>
</html>
