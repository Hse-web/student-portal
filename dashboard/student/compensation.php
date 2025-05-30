<?php
// File: dashboard/student/compensation.php

require_once __DIR__ . '/../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';

$user_id = $_SESSION['user_id'];

// 1) Fetch student + centre
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
    die("Student not found.");
}
$stmt->close();

// 2) Load header
// fetch name & build menu
$stmt = $conn->prepare("SELECT name FROM students WHERE id=?");
$stmt->bind_param('i',$student_id);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

include __DIR__ . '/../../templates/partials/header_student.php';

// 3) If Centre A/B, show _video_ comp UI
if ($centreId !== 3) {
    // a) Count this month’s absences
    $month = date('Y-m');
    $stmt = $conn->prepare("
      SELECT date
        FROM attendance
       WHERE student_id = ?
         AND status     = 'Absent'
         AND DATE_FORMAT(date,'%Y-%m') = ?
    ");
    $stmt->bind_param('is', $student_id, $month);
    $stmt->execute();
    $res = $stmt->get_result();
    $absentDates = [];
    while ($r = $res->fetch_assoc()) {
      $absentDates[] = $r['date'];
    }
    $stmt->close();

    // b) Count how many you’ve already watched this month
    $stmt = $conn->prepare("
      SELECT COUNT(*) 
        FROM attendance
       WHERE student_id    = ?
         AND is_video_comp = 1
         AND DATE_FORMAT(date,'%Y-%m') = ?
    ");
    $stmt->bind_param('is', $student_id, $month);
    $stmt->execute();
    $stmt->bind_result($watchedCount);
    $stmt->fetch();
    $stmt->close();

    // c) Render the video‐comp UI
    ?>
    <div class="card mb-4">
      <div class="card-body">
        <h3 class="card-title">Video Compensation</h3>
        <p>
          <strong>This month:</strong>
          <span class="badge bg-info">
            <?= count($absentDates) ?> Absent &nbsp;|&nbsp; <?= $watchedCount ?> Watched
          </span>
        </p>
        <?php if (empty($absentDates)): ?>
          <div class="alert alert-secondary">
            You have no absences this month.
          </div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($absentDates as $d): 
              $link = "video_compensation.php?date=" . urlencode($d);
            ?>
              <a 
                href="<?= htmlspecialchars($link) ?>"
                class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
              >
                <?= htmlspecialchars($d) ?>
                <?php if (in_array($d, array_column($conn->query("SELECT date FROM attendance WHERE student_id=$student_id AND is_video_comp=1 AND date='$d'")->fetch_all(MYSQLI_ASSOC), 'date'), true)): ?>
                  <span class="badge bg-success">Done</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">Watch</span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    exit;
}

// -------------------------
// 4) Centre C live make-ups
// -------------------------

// Slot definitions
$slotOptions = [
  'fri_17_19' => 'Friday 5–7 pm',
  'sat_10_12' => 'Saturday 10 am–12 pm',
];
$slotToDay = [
  'fri_17_19' => 'Friday',
  'sat_10_12' => 'Saturday',
];

// Flash message
$message = $_SESSION['comp_flash'] ?? '';
unset($_SESSION['comp_flash']);

// Load subscription window
$stmt = $conn->prepare("
  SELECT s.subscribed_at, p.duration_months
    FROM student_subscriptions s
    JOIN payment_plans p ON p.id = s.plan_id
   WHERE s.student_id = ?
   ORDER BY s.id DESC LIMIT 1
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($sub_start, $durationMonths);
if (!$stmt->fetch()) {
  die("No active subscription.");
}
$stmt->close();

$endDate = date('Y-m-d', strtotime("$sub_start +{$durationMonths} months"));
if (date('Y-m-d') > $endDate) {
  die("Subscription ended on $endDate.");
}

$monthlyLimit = match($durationMonths) {
  1 => 1,
  3 => 2,
  6 => 3,
  default => 0,
};

// Used this month
$currentYM = date('Y-m');
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM attendance
   WHERE student_id = ?
     AND is_compensation = 1
     AND DATE_FORMAT(date,'%Y-%m') = ?
");
$stmt->bind_param('is', $student_id, $currentYM);
$stmt->execute();
$stmt->bind_result($usedThisMonth);
$stmt->fetch();
$stmt->close();

// Total used in plan
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM attendance
   WHERE student_id = ?
     AND is_compensation = 1
     AND date BETWEEN ? AND ?
");
$stmt->bind_param('iss', $student_id, $sub_start, $endDate);
$stmt->execute();
$stmt->bind_result($usedTotal);
$stmt->fetch();
$stmt->close();

$maxTotal = $monthlyLimit * $durationMonths;

// Handle POST
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $slot = $_POST['slot'] ?? '';
  if (!isset($slotOptions[$slot])) {
    $_SESSION['comp_flash'] = "Please select a valid slot.";
  }
  elseif ($usedThisMonth < $monthlyLimit) {
    $dayName = $slotToDay[$slot];
    $try = date('Y-m-d', strtotime("next $dayName"));
    if (substr($try,0,7)!==$currentYM) {
      $try = date('Y-m-d', strtotime("first $dayName of this month"));
    }
    $compDate = $try;
  }
  elseif ($usedTotal < $maxTotal) {
    $dayName  = $slotToDay[$slot];
    $compDate = date('Y-m-d', strtotime("first $dayName of +1 month"));
  }
  else {
    $_SESSION['comp_flash'] = "All slots used.";
    header("Location: compensation.php");
    exit;
  }

  // insert request + attendance
  $i = $conn->prepare("
    INSERT INTO compensation_requests (user_id,slot,comp_date,requested_at)
    VALUES (?,?,?,NOW())
  ");
  $i->bind_param('iss',$user_id,$slot,$compDate);
  $i->execute();
  $rid = $i->insert_id; $i->close();

  $a = $conn->prepare("
    INSERT INTO attendance
      (student_id,date,status,is_compensation,compensation_request_id)
    VALUES (?,?, 'Compensation',1,?)
  ");
  $a->bind_param('isi',$student_id,$compDate,$rid);
  $a->execute(); $a->close();

  $_SESSION['comp_flash'] = "Booked {$slotOptions[$slot]} on $compDate";
  header("Location: compensation.php");
  exit;
}

// Render the live‐makeup UI
?>
<div class="card mb-4">
  <div class="card-body">
    <h3 class="card-title">Compensation Classes (Live)</h3>

    <?php if ($message): ?>
      <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <p><strong>Valid until:</strong>
      <span class="badge bg-secondary"><?= htmlspecialchars($endDate) ?></span>
    </p>
    <p><strong>This month:</strong>
      <span class="badge bg-info"><?= "{$usedThisMonth} / {$monthlyLimit}" ?></span>
    </p>
    <p><strong>Total plan:</strong>
      <span class="badge bg-secondary"><?= "{$usedTotal} / {$maxTotal}" ?></span>
    </p>

    <?php if ($usedTotal >= $maxTotal): ?>
      <div class="alert alert-warning">All slots used.</div>
    <?php else: ?>
      <form method="POST" class="row g-3 mt-3">
        <div class="col-md-8">
          <label class="form-label">Choose slot</label>
          <select name="slot" class="form-select" required>
            <option value="">-- pick a slot --</option>
            <?php foreach($slotOptions as $k=>$lbl):?>
              <option value="<?=$k?>"><?=htmlspecialchars($lbl)?></option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="col-md-4 d-grid">
          <button class="btn btn-primary mt-4">Request</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

