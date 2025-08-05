<?php
// File: dashboard/student/compensation.php
// Included via index.php?page=compensation

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    header('Location: /artovue/dashboard/student/index.php?page=compensation');
    exit;
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// 0) Current user → student_id + centre_id
$user_id = intval($_SESSION['user_id'] ?? 0);
if ($user_id < 1) {
    header('Location: /artovue/login.php');
    exit;
}

$stmt = $conn->prepare("
    SELECT id, centre_id
      FROM students
     WHERE user_id = ?
     LIMIT 1
");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$stmt->bind_result($student_id, $centreId);
if (! $stmt->fetch()) {
    $_SESSION['flash_error'] = "Student record not found.";
    header('Location: /artovue/dashboard/student/index.php');
    exit;
}
$stmt->close();

// pull & clear flashes
$ok    = $_SESSION['flash']       ?? '';
$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash'], $_SESSION['flash_error']);

// 1) Video-based centres skip
if (! in_array($centreId, [3,9,10], true)) {
    // … your existing video‐comp logic …
    return;
}

// ─────────────────────────────────────────────────────────────────────────
// Live Make-Up booking for Centres 3,9,10
// ─────────────────────────────────────────────────────────────────────────

// 2) Payment + grace check
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

if (! $hasPay) {
    $error = "No payment found. Please pay before booking a make-up.";
} else {
    $today = date('Y-m-d');
    if ($lastStatus === 'Pending') {
        if (empty($lastPaidAt) || $lastPaidAt === '0000-00-00 00:00:00') {
            $error = "Payment pending. Please clear dues before booking make-ups.";
        } else {
            $firstOfMonth = date('Y-m-01', strtotime($lastPaidAt));
            $graceCutoff  = date('Y-m-d', strtotime("$firstOfMonth +1 month +5 days"));
            if ($today > $graceCutoff) {
                $error = "Payment pending. Please clear dues by <strong>$graceCutoff</strong> to book make-ups.";
            }
        }
    } elseif ($lastStatus !== 'Paid') {
        $error = "Your account is overdue. Please pay before booking a make-up.";
    }
}

// 3) Subscription window
if (! $error) {
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
    if (! $stmt->fetch()) {
        $error = "No active subscription found.";
    }
    $stmt->close();
}

if (! $error) {
    $subEnd = date('Y-m-d', strtotime("$subStart +{$durMonths} months"));
    if (date('Y-m-d') > $subEnd) {
        $error = "Subscription expired on <strong>$subEnd</strong>.";
    }
}

// 4) This-month absences
$absentDates = [];
if (! $error) {
    $monthStr = date('Y-m');
    $stmt = $conn->prepare("
        SELECT date
          FROM attendance
         WHERE student_id = ?
           AND status = 'Absent'
           AND DATE_FORMAT(date,'%Y-%m') = ?
         ORDER BY date
    ");
    $stmt->bind_param('is', $student_id, $monthStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $absentDates[] = $row['date'];
    }
    $stmt->close();
}

// 5) Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $error) {
    $absentDate = $_POST['absent_date'] ?? '';
    $compDate   = $_POST['comp_date']   ?? '';

    // a) validate inputs
    if (! in_array($absentDate, $absentDates, true)) {
        $error = "Please select one of your actual absence dates.";
    } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $compDate)
           || $compDate < substr($subStart,0,10)
           || $compDate > $subEnd) {
        $error = "Make-up date must fall within your subscription period.";
    } else {
        $wd = intval((new DateTime($compDate))->format('w'));
        if (! in_array($wd, [0,3,6], true)) {
            $error = "Only Wednesday, Saturday or Sunday are allowed for make-ups.";
        }
    }

    // b) duplicate absence booking
    if (! $error) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
              FROM compensation_requests
             WHERE user_id = ?
               AND absent_date = ?
        ");
        $stmt->bind_param('is', $user_id, $absentDate);
        $stmt->execute();
        $stmt->bind_result($already);
        $stmt->fetch();
        $stmt->close();
        if ($already > 0) {
            $error = "You’ve already booked a make-up for $absentDate.";
        }
    }

    // c) CAP check
    if (! $error) {
        // per-month entitlement
        switch ($durMonths) {
            case 3: $perMonth = 2; break;
            case 6: $perMonth = 3; break;
            default: $perMonth = 1; break;
        }
        $totalAllowed = $perMonth * $durMonths;

        // how many already booked in FULL window
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
              FROM compensation_requests
             WHERE user_id = ?
               AND comp_date BETWEEN ? AND ?
        ");
        $stmt->bind_param('iss', $user_id, $subStart, $subEnd);
        $stmt->execute();
        $stmt->bind_result($bookedTotal);
        $stmt->fetch();
        $stmt->close();

        // count bookings LAST calendar-month of window
        $lmStart = date('Y-m-01', strtotime($subEnd));
        $lmEnd   = $subEnd;
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
              FROM compensation_requests
             WHERE user_id = ?
               AND comp_date BETWEEN ? AND ?
        ");
        $stmt->bind_param('iss', $user_id, $lmStart, $lmEnd);
        $stmt->execute();
        $stmt->bind_result($bookedLastMonth);
        $stmt->fetch();
        $stmt->close();

        // how many remain
        $remaining = $totalAllowed - $bookedTotal;

        // **carry-over only for multi-month plans**
        if ($durMonths > 1 && $remaining <= 0 && $bookedLastMonth === 0) {
            $remaining = 1;
        }

        if ($remaining <= 0) {
            $error = sprintf(
              "You’ve already booked %d make-ups for your %d-month plan (max %d).",
              $bookedTotal,
              $durMonths,
              $totalAllowed
            );
        }
    }

    // d) finally, insert if no errors
    if (! $error) {
        // slot key by weekday
        if      ($wd === 6) $slot = 'sat_11_30';
        elseif  ($wd === 0) $slot = 'sun_16_18';
        else                $slot = 'wed_17_19';

        $stmt = $conn->prepare("
            INSERT INTO compensation_requests
              (user_id, absent_date, slot, comp_date, requested_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param('isss', $user_id, $absentDate, $slot, $compDate);
        $stmt->execute();
        $rid = $conn->insert_id;
        $stmt->close();

        // also insert a Compensation attendance record
        $stmt = $conn->prepare("
            INSERT INTO attendance
              (student_id, date, status, is_compensation, compensation_request_id)
            VALUES (?, ?, 'Compensation', 1, ?)
        ");
        $stmt->bind_param('isi', $student_id, $compDate, $rid);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash'] = sprintf(
            "Booked <strong>%s</strong> on <strong>%s</strong> for your absence on <strong>%s</strong>.",
            strtoupper($slot),
            $compDate,
            $absentDate
        );
        header('Location: /artovue/dashboard/student/index.php?page=compensation');
        exit;
    }
}

// 6) Build allowed dates for JS datepicker
$allowed = [];
if (! $error && count($absentDates) > 0) {
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

<div class="container my-4">
  <h2>Compensation Classes (Live)</h2>

  <?php if ($ok): ?>
    <div class="alert alert-success"><?= $ok ?></div>
  <?php elseif ($error): ?>
    <div class="alert alert-danger"><?= $error ?></div>
  <?php endif; ?>

  <?php if (empty($absentDates) && ! $error): ?>
    <div class="alert alert-info">You have no absences this month.</div>
  <?php elseif (! $error): ?>
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

<!-- jQuery UI datepicker -->
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
