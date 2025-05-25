<?php
// File: dashboard/attendance.php

// 1) Bootstrap session & DB
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';

// 2) Auth guard: only students
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../login/index.php');
    exit;
}

// 3) Identify student
$studentId = (int)($_SESSION['student_id'] ?? 0);

// 3a) Fetch the student’s centre_id
$stmt = $conn->prepare("SELECT centre_id FROM students WHERE id = ?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($centreId);
$stmt->fetch();
$stmt->close();

// 4) Determine which month to show
$month = $_GET['month'] ?? date('Y-m');
try {
    $dt = new DateTime("$month-01");
} catch (Exception $e) {
    $dt = new DateTime();
    $month = $dt->format('Y-m');
}
$prev  = (clone $dt)->modify('-1 month');
$next  = (clone $dt)->modify('+1 month');
$label = $dt->format('F Y');

// 5) Pull attendance rows for this student/month
$stmt = $conn->prepare("
  SELECT date, status
    FROM attendance
   WHERE student_id = ?
     AND DATE_FORMAT(date,'%Y-%m') = ?
");
$stmt->bind_param('is', $studentId, $month);
$stmt->execute();
$res = $stmt->get_result();
$att = [];
while ($row = $res->fetch_assoc()) {
    $att[$row['date']] = $row['status'];
}
$stmt->close();

// 6) Compute summary stats
$totalRecorded = count($att);
$presentCount  = count(array_filter($att, fn($s)=> $s==='Present'));
$absentCount   = count(array_filter($att, fn($s)=> $s==='Absent'));
$compCount     = count(array_filter($att, fn($s)=> $s==='Compensation'));

// 7) Calendar grid params
$firstDow    = (int)$dt->format('w');   // 0=Sun…6=Sat
$daysInMonth = (int)$dt->format('t');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Attendance — <?= htmlspecialchars($label) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body { background:#1e1e2f; color:#ddd; }
    .topbar, .legend { background:#2b2b3f; }
    .topbar { padding:1rem; }
    .summary { padding:.75rem 1rem; background:#27273b; color:#fff; }
    .cal-cell { height:100px; vertical-align:top; }
    .cal-cell .date { font-size:.9rem; }
    .status-dot {
      display:inline-block; width:1.5rem; height:1.5rem;
      line-height:1.5rem; border-radius:50%; color:#fff; font-weight:bold;
      text-align:center; margin-top:.25rem;
    }
    .present      { background:#28a745; }
    .absent       { background:#dc3545; }
    .compensation { background:#ffc107; color:#212529; }
    .legend .btn { cursor:default; }
    table.calendar { table-layout:fixed; }
    table.calendar th, table.calendar td { width:14.28%; }
    .watch-btn { font-size:.75rem; padding:.25rem .5rem; }
  </style>
</head>
<body>

<div class="container py-4">
  <!-- Month nav -->
  <div class="topbar rounded-top d-flex align-items-center justify-content-between text-light mb-0">
    <a href="attendance.php?month=<?= $prev->format('Y-m') ?>" class="btn btn-sm btn-outline-light">
      <i class="bi bi-chevron-left"></i>
    </a>
    <h4 class="m-0"><?= htmlspecialchars($label) ?></h4>
    <a href="attendance.php?month=<?= $next->format('Y-m') ?>" class="btn btn-sm btn-outline-light">
      <i class="bi bi-chevron-right"></i>
    </a>
  </div>

  <!-- Summary -->
  <div class="summary">
    <strong>Total Recorded:</strong> <?= $totalRecorded ?> &nbsp;|&nbsp;
    <span class="text-success"><i class="bi bi-check-circle-fill"></i> <?= $presentCount ?></span> &nbsp;|&nbsp;
    <span class="text-danger"><i class="bi bi-x-circle-fill"></i> <?= $absentCount ?></span> &nbsp;|&nbsp;
    <span class="text-warning"><i class="bi bi-clock-history"></i> <?= $compCount ?></span>
  </div>

  <!-- Status legend -->
  <div class="legend px-3 pt-3 pb-2 rounded-bottom text-center mb-4">
    <button class="btn btn-sm present"><i class="bi bi-check-circle"></i> Present</button>
    <button class="btn btn-sm absent"><i class="bi bi-x-circle"></i> Absent</button>
    <button class="btn btn-sm compensation"><i class="bi bi-clock-history"></i> Compensation</button>
  </div>

  <!-- Calendar -->
  <div class="table-responsive mb-4">
    <table class="calendar table table-dark table-bordered text-center rounded-bottom">
      <thead>
        <tr class="text-uppercase small">
          <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th>
          <th>Thu</th><th>Fri</th><th>Sat</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $day = 1;
        for ($row=0; $row<6; $row++):
          echo '<tr>';
          for ($dow=0; $dow<7; $dow++):
            if (($row===0 && $dow < $firstDow) || $day > $daysInMonth) {
              echo '<td class="cal-cell bg-dark"></td>';
            } else {
              $dateKey = $dt->format('Y-m') . '-' . str_pad($day,2,'0',STR_PAD_LEFT);
              $status  = $att[$dateKey] ?? '';
              $cls     = match($status) {
                'Present'      => 'present',
                'Absent'       => 'absent',
                'Compensation' => 'compensation',
                default        => ''
              };
              echo '<td class="cal-cell">';
              echo '<div class="date">'. $day .'</div>';

              // Show status dot if present/absent/comp
              if ($cls) {
                echo '<div class="status-dot '. $cls .'">'
                     . strtoupper(substr($status,0,1))
                     . '</div>';
              }

              // For Absent in Centre A/B, show Watch Video button
              if ($status === 'Absent' && $centreId !== 3) {
                echo '<div class="mt-2">'
                   .  '<a href="video_compensation.php?date='
                   .    $dateKey
                   .  '" class="btn btn-warning btn-sm watch-btn">'
                   .    'Watch Video'
                   .  '</a>'
                   .  '</div>';
              }

              echo '</td>';
              $day++;
            }
          endfor;
          echo '</tr>';
          if ($day > $daysInMonth) break;
        endfor;
        ?>
      </tbody>
    </table>
  </div>

  <!-- Bottom legend text -->
  <div class="text-center small text-light">
    <span class="text-success"><i class="bi bi-check-circle-fill"></i> Present</span> |
    <span class="text-danger"><i class="bi bi-x-circle-fill"></i> Absent</span> |
    <span class="text-warning"><i class="bi bi-clock-history"></i> Compensation</span>
  </div>
</div>

</body>
</html>
