<?php
// File: dashboard/student/compensation.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

// PRG flash
$user_id = $_SESSION['user_id'];
$message = $_SESSION['comp_flash'] ?? '';
unset($_SESSION['comp_flash']);

// Slot definitions
$slotOptions = [
  'fri_17_19' => 'Friday 5–7 pm',
  'sat_10_12' => 'Saturday 10 am–12 pm',
];
$slotToDay = [
  'fri_17_19' => 'Friday',
  'sat_10_12' => 'Saturday',
];

// Fetch student_id + centre
$stmt = $conn->prepare("
  SELECT id, centre_id
    FROM students
   WHERE user_id = ?
   LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $centreId);
if (!$stmt->fetch()) {
    die("Student record not found.");
}
$stmt->close();

// Only Centre C sees this form
if ($centreId !== 3) {
    header("Location: video_compensation.php");
    exit;
}

// Load subscription + duration
$stmt = $conn->prepare("
  SELECT s.subscribed_at, p.duration_months
    FROM student_subscriptions s
    JOIN payment_plans p ON s.plan_id = p.id
   WHERE s.student_id = ?
   ORDER BY s.id DESC
   LIMIT 1
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($sub_start, $duration_months);
if (!$stmt->fetch()) {
    die("No active subscription found.");
}
$stmt->close();

// Compute end date
$end_date = date('Y-m-d', strtotime("$sub_start +{$duration_months} month"));
if (date('Y-m-d') > $end_date) {
    die("Subscription ended on $end_date.");
}

// Monthly limit
$monthlyLimit = match($duration_months) {
    1 => 1,
    3 => 2,
    6 => 3,
    default => 0,
};

// Count used this month (attendance.date)
$currentMonth = date('m');
$currentYear  = date('Y');
$stmt = $conn->prepare("
  SELECT COUNT(*) FROM attendance
   WHERE student_id      = ?
     AND is_compensation = 1
     AND MONTH(`date`)   = ?
     AND YEAR(`date`)    = ?
");
$stmt->bind_param('iii', $student_id, $currentMonth, $currentYear);
$stmt->execute();
$stmt->bind_result($usedThisMonth);
$stmt->fetch();
$stmt->close();

// Count total in plan (attendance.date)
$stmt = $conn->prepare("
  SELECT COUNT(*) FROM attendance
   WHERE student_id      = ?
     AND is_compensation = 1
     AND `date` BETWEEN ? AND ?
");
$stmt->bind_param('iss', $student_id, $sub_start, $end_date);
$stmt->execute();
$stmt->bind_result($usedTotal);
$stmt->fetch();
$stmt->close();

$maxTotal = $monthlyLimit * $duration_months;

// Handle form POST → PRG
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slot = $_POST['slot'] ?? '';
    if (!isset($slotOptions[$slot])) {
        $_SESSION['comp_flash'] = "Please select a valid slot.";
    } elseif ($usedThisMonth >= $monthlyLimit) {
        $_SESSION['comp_flash'] = "Monthly limit reached → scheduling next month.";
    } else {
        // Determine compensation date
        $dayName = $slotToDay[$slot];
        if ($usedThisMonth >= $monthlyLimit) {
            // First day of next month + offset
            $firstNext = date('Y-m-01', strtotime('first day of +1 month'));
            $firstDow  = date('N', strtotime($firstNext));
            $targetDow = date('N', strtotime($dayName));
            $offset    = ($targetDow - $firstDow + 7) % 7;
            $compDate  = date('Y-m-d', strtotime("$firstNext +{$offset} days"));
        } else {
            $today = date('l');
            $compDate = $today === $dayName
                      ? date('Y-m-d')
                      : date('Y-m-d', strtotime("next $dayName"));
        }

        // Insert request
        $ins = $conn->prepare("
          INSERT INTO compensation_requests
            (user_id, slot, comp_date, requested_at)
          VALUES (?, ?, ?, NOW())
        ");
        $ins->bind_param('iss', $user_id, $slot, $compDate);
        $ins->execute();
        $reqId = $ins->insert_id;
        $ins->close();

        // Mark attendance
        $att = $conn->prepare("
          INSERT INTO attendance
            (student_id, date, status, is_compensation, compensation_request_id)
          VALUES (?, ?, 'Compensation', 1, ?)
        ");
        $att->bind_param('isi', $student_id, $compDate, $reqId);
        $att->execute();
        $att->close();

        $_SESSION['comp_flash'] = "Booked “{$slotOptions[$slot]}” on $compDate.";
    }
    header("Location: compensation.php");
    exit;
}
?>
<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <h3>Compensation Classes</h3>
      <?php if ($message): ?>
        <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>
      <p><strong>Valid until:</strong>
        <span class="badge bg-secondary"><?= htmlspecialchars($end_date) ?></span>
      </p>
      <p><strong>This month:</strong>
        <span class="badge bg-info"><?= htmlspecialchars("$usedThisMonth / $monthlyLimit") ?></span>
      </p>
      <p><strong>Total:</strong>
        <span class="badge bg-secondary"><?= htmlspecialchars("$usedTotal / $maxTotal") ?></span>
      </p>

      <?php if ($usedThisMonth < $monthlyLimit): ?>
        <form method="POST" class="row g-3 mt-2">
          <div class="col-md-8">
            <label class="form-label">Choose a slot</label>
            <select name="slot" class="form-select" required>
              <option value="">-- pick a slot --</option>
              <?php foreach ($slotOptions as $code => $label): ?>
                <option value="<?= $code ?>"><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4 d-grid">
            <button class="btn btn-primary mt-4">Request Compensation</button>
          </div>
        </form>
      <?php else: ?>
        <div class="alert alert-warning mt-3">
          No more slots available this month.
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

