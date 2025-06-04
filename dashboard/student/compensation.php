<?php
// ───────────────────────────────────────────────────────────────────────────────
// File: dashboard/student/compensation.php
//
// This page:
//  1) Loads Bootstrap 5 CSS in the <head> so the navbar, cards, badges, etc. are styled.
//  2) Shows a minimal navbar (“Student Portal”) so you see a proper header.
//  3) Uses your exact logic for Centre A/B vs Centre C compensation.
//  4) Uses JavaScript redirects instead of PHP header() calls, avoiding “headers already sent”.
//  5) Loads Bootstrap’s JS bundle at the very bottom, so tabs or any JS components (if you add them later) work correctly.
//  6) Assumes your project root in XAMPP is C:\xampp\htdocs\student-portal
//     and the URL to this file is:
//       http://localhost/student-portal/dashboard/student/compensation.php
// ───────────────────────────────────────────────────────────────────────────────

$page = 'compensation';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$user_id = intval($_SESSION['user_id'] ?? 0);
if ($user_id < 1) {
    // JavaScript redirect if not logged in
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
    echo '<script>window.location.href = "/student-portal/login.php";</script>';
    echo '</body></html>';
    exit;
}

// 1) Fetch student_id and centre_id from students table
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
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>';
    echo '<div class="container my-5"><div class="alert alert-danger">Error: Student not found.</div>';
    echo '<a href="/student-portal/dashboard/student/index.php" class="btn btn-secondary">Back to Dashboard</a>';
    echo '</div></body></html>';
    exit;
}
$stmt->close();

// 2) Fetch the student’s name (for the navbar greeting)
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// Now render the HTML with Bootstrap styling:
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Compensation Classes</title>
  <!-- Load Bootstrap 5 CSS from CDN -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
    integrity="sha384-9ndCyUa6mYQ6IBQ1UKDqBKE6xvF0ThxiVazxkZ+rTZ8C7cH+8E2rX8OyIG3Fj4Xo"
    crossorigin="anonymous"
  >
  <!-- (Optional) Your custom dashboard CSS -->
  <link rel="stylesheet" href="/student-portal/assets/css/dashboard.css">
</head>
<body>
  <!-- ─── Minimal Bootstrap Navbar ─────────────────────────────────────────── -->
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
    <div class="container-fluid">
      <a class="navbar-brand" href="/student-portal/dashboard/student/index.php">
        Student Portal
      </a>
      <button
        class="navbar-toggler"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#mainNav"
        aria-controls="mainNav"
        aria-expanded="false"
        aria-label="Toggle navigation"
      >
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item">
            <span class="nav-link">Hello, <?= htmlspecialchars($studentName) ?></span>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/student-portal/dashboard/student/profile.php">Profile</a>
          </li>
          <li class="nav-item">
            <a class="nav-link" href="/student-portal/logout.php">Logout</a>
          </li>
        </ul>
      </div>
    </div>
  </nav>

  <!-- ─── Main Content Container ────────────────────────────────────────────── -->
  <div class="container my-4">
    <?php
    // ─── 3) Centre A/B: Render Video Compensation UI ────────────────────────
    if ($centreId !== 3) {
        // a) Count absences this month
        $month = date('Y-m');
        $stmt = $conn->prepare("
          SELECT date
            FROM attendance
           WHERE student_id = ?
             AND status = 'Absent'
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

        // b) Count how many video comps already watched this month
        $stmt = $conn->prepare("
          SELECT COUNT(*)
            FROM attendance
           WHERE student_id = ?
             AND is_video_comp = 1
             AND DATE_FORMAT(date,'%Y-%m') = ?
        ");
        $stmt->bind_param('is', $student_id, $month);
        $stmt->execute();
        $stmt->bind_result($watchedCount);
        $stmt->fetch();
        $stmt->close();

        // c) Render the Video Compensation card
        ?>
        <div class="card mb-4 shadow-sm">
          <div class="card-body">
            <h3 class="card-title">Video Compensation</h3>
            <p>
              <strong>This month:</strong>
              <span class="badge bg-info">
                <?= count($absentDates) ?> Absent &nbsp;|&nbsp; <?= intval($watchedCount) ?> Watched
              </span>
            </p>

            <?php if (empty($absentDates)): ?>
              <div class="alert alert-secondary">
                You have no absences this month.
              </div>
            <?php else: ?>
              <div class="list-group">
                <?php
                // Pre‐fetch the list of dates where is_video_comp = 1, to mark “Done”
                $placeholders = implode(',', array_fill(0, count($absentDates), '?'));
                $types = str_repeat('s', count($absentDates));
                $sql = "
                  SELECT date
                    FROM attendance
                   WHERE student_id = ?
                     AND is_video_comp = 1
                     AND date IN ($placeholders)
                ";
                $stmt2 = $conn->prepare($sql);

                // Build param list: first “i” for student_id, then all “s” for each date
                $paramTypes = 'i' . $types;
                $params = array_merge([$paramTypes, &$student_id], $absentDates);
                call_user_func_array([$stmt2, 'bind_param'], $params);

                $stmt2->execute();
                $res2 = $stmt2->get_result();
                $alreadyWatched = [];
                while ($row2 = $res2->fetch_assoc()) {
                    $alreadyWatched[] = $row2['date'];
                }
                $stmt2->close();
                ?>

                <?php foreach ($absentDates as $d):
                  $link = "/student-portal/dashboard/student/video_compensation.php?date=" . urlencode($d);
                ?>
                  <a
                    href="<?= htmlspecialchars($link) ?>"
                    class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                  >
                    <?= htmlspecialchars($d) ?>
                    <?php if (in_array($d, $alreadyWatched, true)): ?>
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
        // Stop here—Centre A/B should not see the “live make-ups” UI
        exit;
    }

    // ─── 4) Centre C: Live Make‐Up Slots UI ─────────────────────────────────
    $slotOptions = [
      'fri_17_19' => 'Friday 5–7 pm',
      'sat_10_12' => 'Saturday 10 am–12 pm',
    ];
    $slotToDay = [
      'fri_17_19' => 'Friday',
      'sat_10_12' => 'Saturday',
    ];

    // Flash message (if any)
    $message = $_SESSION['comp_flash'] ?? '';
    unset($_SESSION['comp_flash']);

    // Load subscription window (start date + plan duration)
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
      echo '<div class="alert alert-danger mt-4">No active subscription found.</div>';
      echo '<a href="/student-portal/dashboard/student/index.php" class="btn btn-secondary">Back to Dashboard</a>';
      exit;
    }
    $stmt->close();

    $endDate = date('Y-m-d', strtotime("$sub_start +{$durationMonths} months"));
    if (date('Y-m-d') > $endDate) {
      echo '<div class="alert alert-danger mt-4">Subscription expired on ' . htmlspecialchars($endDate) . '.</div>';
      echo '<a href="/student-portal/dashboard/student/index.php" class="btn btn-secondary">Back to Dashboard</a>';
      exit;
    }

    $monthlyLimit = match($durationMonths) {
      1 => 1,
      3 => 2,
      6 => 3,
      default => 0,
    };

    // Count how many compensation sessions used this month
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

    // Count how many have been used over the entire plan period
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

    // Handle slot‐booking POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $slot = $_POST['slot'] ?? '';
        if (!isset($slotOptions[$slot])) {
            $_SESSION['comp_flash'] = "Please select a valid slot.";
        }
        elseif ($usedThisMonth < $monthlyLimit) {
            // Book within current month if under monthly limit
            $dayName = $slotToDay[$slot];
            $try = date('Y-m-d', strtotime("next $dayName"));
            if (substr($try, 0, 7) !== $currentYM) {
                $try = date('Y-m-d', strtotime("first $dayName of this month"));
            }
            $compDate = $try;
        }
        elseif ($usedTotal < $maxTotal) {
            // Book in next month if monthly limit reached but overall plan not exhausted
            $dayName  = $slotToDay[$slot];
            $compDate = date('Y-m-d', strtotime("first $dayName of +1 month"));
        }
        else {
            $_SESSION['comp_flash'] = "All slots used.";
            echo '<script>window.location.href = "/student-portal/dashboard/student/compensation.php";</script>';
            exit;
        }

        // Insert a new compensation request
        $i = $conn->prepare("
          INSERT INTO compensation_requests (user_id, slot, comp_date, requested_at)
          VALUES (?, ?, ?, NOW())
        ");
        $i->bind_param('iss', $user_id, $slot, $compDate);
        $i->execute();
        $rid = $i->insert_id;
        $i->close();

        // Insert an attendance record marking it as “Compensation”
        $a = $conn->prepare("
          INSERT INTO attendance
            (student_id, date, status, is_compensation, compensation_request_id)
          VALUES (?, ?, 'Compensation', 1, ?)
        ");
        $a->bind_param('isi', $student_id, $compDate, $rid);
        $a->execute();
        $a->close();

        $_SESSION['comp_flash'] = "Booked “{$slotOptions[$slot]}” on $compDate";
        echo '<script>window.location.href = "/student-portal/dashboard/student/compensation.php";</script>';
        exit;
    }
    ?>

    <!-- ─── 5) Render Live Make‐Up Slot Booking Card for Centre C ──────────── -->
    <div class="card shadow-sm">
      <div class="card-body">
        <h3 class="card-title">Compensation Classes (Live)</h3>

        <?php if ($message): ?>
          <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <p>
          <strong>Valid until:</strong>
          <span class="badge bg-secondary"><?= htmlspecialchars($endDate) ?></span>
        </p>
        <p>
          <strong>This month:</strong>
          <span class="badge bg-info"><?= "{$usedThisMonth} / {$monthlyLimit}" ?></span>
        </p>
        <p>
          <strong>Total plan:</strong>
          <span class="badge bg-secondary"><?= "{$usedTotal} / {$maxTotal}" ?></span>
        </p>

        <?php if ($usedTotal >= $maxTotal): ?>
          <div class="alert alert-warning">
            All slots used.
          </div>
        <?php else: ?>
          <form method="POST" class="row g-3 mt-3">
            <div class="col-md-8">
              <label class="form-label">Choose slot</label>
              <select name="slot" class="form-select" required>
                <option value="">-- pick a slot --</option>
                <?php foreach ($slotOptions as $k => $lbl): ?>
                  <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-grid">
              <button class="btn btn-primary mt-4">Request</button>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div><!-- /.container -->

  <!-- ─── Bootstrap 5 JS bundle (only once, at the very end) ───────────────── -->
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
    integrity="sha384-9ndCyUa6mYQ6IBQ1UKDqBKE6xvF0ThxiVazxkZ+rTZ8C7cH+8E2rX8OyIG3Fj4Xo"
    crossorigin="anonymous"
  ></script>
</body>
</html>
