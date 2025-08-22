<?php
// File: dashboard/student/compensation.php
// Included via index.php?page=compensation

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
  echo '<script>window.location.href = "/artovue/dashboard/student/index.php?page=compensation";</script>';
  exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$user_id = intval($_SESSION['user_id'] ?? 0);
if ($user_id < 1) {
  header('Location: /artovue/login.php');
  exit;
}

// 1) Fetch student_id + centre_id\[$
$stmt = $conn->prepare("SELECT id, centre_id FROM students WHERE user_id = ? LIMIT 1");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $centreId);
if (!$stmt->fetch()) {
  $_SESSION['flash_error'] = "Student record not found.";
  header('Location: /artovue/dashboard/student/index.php');
  exit;
}
$stmt->close();

// 2) Pull + clear flashes
$ok    = $_SESSION['flash']       ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

// 3) Non-centre-3: Video Compensation
if ($centreId !== 3) {
  $month = date('Y-m');

  // a) Absent dates
  $stmt = $conn->prepare(
    "SELECT date FROM attendance
     WHERE student_id = ?
       AND status = 'Absent'
       AND DATE_FORMAT(`date`, '%Y-%m') = ?
     ORDER BY date"
  );
  $stmt->bind_param('is', $student_id, $month);
  $stmt->execute();
  $absentDates = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'date');
  $stmt->close();

  // b) Watched count via video_completions
  $stmt = $conn->prepare(
    "SELECT COUNT(*)
       FROM video_completions vc
       JOIN compensation_videos v ON v.id = vc.video_id
      WHERE vc.student_id = ?
        AND DATE_FORMAT(v.class_date, '%Y-%m') = ?"
  );
  $stmt->bind_param('is', $student_id, $month);
  $stmt->execute();
  $stmt->bind_result($watchedCount);
  $stmt->fetch();
  $stmt->close();

  // c) Watched dates list
  $stmt = $conn->prepare(
    "SELECT v.class_date
       FROM video_completions vc
       JOIN compensation_videos v ON v.id = vc.video_id
      WHERE vc.student_id = ?
        AND DATE_FORMAT(v.class_date, '%Y-%m') = ?"
  );
  $stmt->bind_param('is', $student_id, $month);
  $stmt->execute();
  $watchedDates = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'class_date');
  $stmt->close();

  // Render
  ?>
  <div class="container my-4">
    <h2>Video Compensation</h2>
    <p><strong>This month:</strong>
      <span class="badge bg-info"><?= count($absentDates) ?> Absent&nbsp;|&nbsp;<?= intval($watchedCount) ?> Watched</span>
    </p>

    <?php if (empty($absentDates)): ?>
      <div class="alert alert-secondary">You have no absences this month.</div>
    <?php else: ?>
      <div class="list-group">
        <?php foreach ($absentDates as $d):
          $link = "index.php?page=video_compensation&date=" . urlencode($d);
        ?>
          <a href="<?= htmlspecialchars($link) ?>"
             class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
            <?= htmlspecialchars($d) ?>
            <?php if (in_array($d, $watchedDates, true)): ?>
              <span class="badge bg-success">Done</span>
            <?php else: ?>
              <span class="badge bg-warning text-dark">Watch</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php
  return;
}
// ───────────────────────────────────────────────────────────────────────
// Centre C: Live Make-Up booking
// ───────────────────────────────────────────────────────────────────────

// 1) Payment + grace check
$stmt = $conn->prepare("
  SELECT status, paid_at
    FROM payments
   WHERE student_id = ?
   ORDER BY paid_at DESC
   LIMIT 1
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($lastStatus, $lastPaidAt);
$hasPay = $stmt->fetch();
$stmt->close();

if (!$hasPay) {
  $error = "No payment found. Please pay before booking a make-up.";
} else {
  $today = date('Y-m-d');
  if ($lastStatus === 'Paid') {
    // ok
  }
  elseif ($lastStatus === 'Pending') {
    if (empty($lastPaidAt) || $lastPaidAt === '0000-00-00 00:00:00') {
      $error = "Payment pending. Please clear dues before booking make-ups.";
    } else {
      $firstOfMonth = date('Y-m-01', strtotime($lastPaidAt));
      $graceCutoff  = date('Y-m-d', strtotime("$firstOfMonth +1 month +5 days"));
      if ($today > $graceCutoff) {
        $error = "Payment pending. Please clear dues by <strong>$graceCutoff</strong> to book make-ups.";
      }
    }
  }
  else {
    $error = "Your account is overdue. Please pay before booking a make-up.";
  }
}

// 2) Subscription window
if (!$error) {
  $stmt = $conn->prepare("
    SELECT s.subscribed_at, p.duration_months
      FROM student_subscriptions s
      JOIN payment_plans p ON p.id = s.plan_id
     WHERE s.student_id = ?
     ORDER BY s.id DESC
     LIMIT 1
  ");
  $stmt->bind_param('i', $student_id);
  $stmt->execute();
  $stmt->bind_result($subStart, $durMonths);
  if (!$stmt->fetch()) {
    $error = "No active subscription found.";
  }
  $stmt->close();
}

if (!$error) {
  $subEnd = date('Y-m-d', strtotime("$subStart +{$durMonths} months"));
  if (date('Y-m-d') > $subEnd) {
    $error = "Subscription expired on <strong>$subEnd</strong>.";
  }
}

// 3) This-month absences
$absentDates = [];
if (!$error) {
  $m = date('Y-m');
  $stmt = $conn->prepare("
    SELECT date
      FROM attendance
     WHERE student_id = ?
       AND status = 'Absent'
       AND DATE_FORMAT(date,'%Y-%m') = ?
     ORDER BY date
  ");
  $stmt->bind_param('is', $student_id, $m);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $absentDates[] = $row['date'];
  }
  $stmt->close();
}

// 4) Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
  $absentDate = $_POST['absent_date'] ?? '';
  $compDate   = $_POST['comp_date']   ?? '';

  if (!in_array($absentDate, $absentDates, true)) {
    $error = "Please select one of your actual absence dates.";
  }
  elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$compDate)
      || $compDate < substr($subStart,0,10)
      || $compDate > $subEnd
  ) {
    $error = "Make-up date must fall within your subscription period.";
  }
  else {
    $wd = intval((new DateTime($compDate))->format('w'));
    if (!in_array($wd, [0,3,6], true)) {
      $error = "Only Wednesday, Saturday or Sunday are allowed for a make-up.";
    }
  }

  if (!$error) {
    // duplicate?
    $chk = $conn->prepare("
      SELECT COUNT(*) FROM compensation_requests
       WHERE user_id = ? AND absent_date = ?
    ");
    $chk->bind_param('is', $user_id, $absentDate);
    $chk->execute();
    $chk->bind_result($already);
    $chk->fetch();
    $chk->close();
    if ($already > 0) {
      $error = "You’ve already booked a make-up for $absentDate.";
    }
  }

  if (!$error) {
    // slot key
    if ($wd === 6)      $slot = 'sat_10_12';
    elseif ($wd === 0)  $slot = 'sun_10_12';
    else                $slot = 'wed_10_12';

    // insert
    $i = $conn->prepare("
      INSERT INTO compensation_requests
        (user_id, absent_date, slot, comp_date, requested_at)
      VALUES (?, ?, ?, ?, NOW())
    ");
    $i->bind_param('isss', $user_id, $absentDate, $slot, $compDate);
    $i->execute();
    $i->close();

    $rid = $conn->insert_id;
    $a = $conn->prepare("
      INSERT INTO attendance
        (student_id, date, status, is_compensation, compensation_request_id)
      VALUES (?, ?, 'Compensation', 1, ?)
    ");
    $a->bind_param('isi', $student_id, $compDate, $rid);
    $a->execute();
    $a->close();

    $_SESSION['flash'] = "Booked <strong>$slot</strong> on <strong>$compDate</strong> for your absence on <strong>$absentDate</strong>.";
    echo '<script>window.location.href = "/artovue/dashboard/student/index.php?page=compensation";</script>';
    exit;
  }
}

// 5) Build allowed dates up to subEnd
$allowed = [];
if (!$error && count($absentDates)) {
  $dt  = new DateTime('today');
  $end = new DateTime($subEnd);
  while ($dt <= $end) {
    $w = intval($dt->format('w'));
    if (in_array($w, [0,3,6], true)) {
      $allowed[] = $dt->format('Y-m-d');
    }
    $dt->modify('+1 day');
  }
}
$jsAllowed = json_encode($allowed, JSON_HEX_TAG);
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6b21a8">
<link rel="icon" href="/assets/icons/icon-192.png">
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
      .then(() => console.log('✅ SW registered'))
      .catch(err => console.error('⚠️ SW registration failed:', err));
  }
</script>
<div class="container my-4">
  <h2>Compensation Classes (Live)</h2>

  <?php if ($ok): ?>
    <div class="alert alert-success"><?= $ok ?></div>
  <?php elseif ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <?php if (empty($absentDates) && !$error): ?>
    <div class="alert alert-info">You have no absences this month.</div>
  <?php elseif (!$error): ?>
    <form method="POST" class="card card-body w-50">
      <div class="mb-3">
        <label class="form-label">Which absence?</label>
        <select name="absent_date" class="form-select" required>
          <option value="">— pick a date —</option>
          <?php foreach ($absentDates as $d): ?>
            <option value="<?= $d ?>"
              <?= (($_POST['absent_date'] ?? '') === $d) ? 'selected' : '' ?>>
              <?= $d ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Pick your make-up date</label>
        <input
          id="comp_date"
          name="comp_date"
          class="form-control"
          placeholder="Click a highlighted date"
          readonly
          required
          value="<?= htmlspecialchars($_POST['comp_date'] ?? '') ?>"
        >
      </div>
      <button class="btn btn-primary">Request Make-Up</button>
    </form>
  <?php endif; ?>
</div>

<!-- jQuery & jQuery UI for datepicker -->
<script src="//code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="//code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<link rel="stylesheet" href="//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">

<script>
  $(function(){
    const allowed = <?= $jsAllowed ?>;
    $("#comp_date").datepicker({
      dateFormat: 'yy-mm-dd',
      beforeShowDay: d => {
        const s = $.datepicker.formatDate('yy-mm-dd', d);
        return [ allowed.includes(s), 'allowed-day', 'Available' ];
      },
      minDate: 0
    });
  });
</script>
<style>
  .allowed-day a {
    background-color: #ffc107 !important;
    color: #000 !important;
  }
</style>
