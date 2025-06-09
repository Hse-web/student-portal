<?php
// ───────────────────────────────────────────────────────────────────────────────
// File: dashboard/student/attendance.php
// Renders a month-view calendar. Any day with status='Absent' becomes a “Make-Up” button.
// ───────────────────────────────────────────────────────────────────────────────

$page = 'attendance';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = $_SESSION['student_id'];

// ─── Determine which month to display (YYYY-MM) ────────────────────────────
$month = $_GET['month'] ?? date('Y-m');
try {
    $dt = new DateTime("{$month}-01");
} catch (Exception $e) {
    $dt = new DateTime();
    $month = $dt->format('Y-m');
}
$prev  = (clone $dt)->modify('-1 month')->format('Y-m');
$next  = (clone $dt)->modify('+1 month')->format('Y-m');
$label = $dt->format('F Y');

// Build an array of days-of-month, padded to start on Sunday (0) … Saturday (6)
$days  = (int)$dt->format('t');
$start = (int)$dt->format('w'); // 0=Sunday, 6=Saturday
$cells = array_pad([], $start, null);
for ($d = 1; $d <= $days; $d++) {
    $cells[] = $d;
}
while (count($cells) % 7 !== 0) {
    $cells[] = null;
}
$weeks = array_chunk($cells, 7);

// ─── Fetch attendance statuses for this student, for the chosen month ─────
$attMap = [];
$stmt = $conn->prepare("
  SELECT `date`, `status`
    FROM attendance
   WHERE student_id = ?
     AND DATE_FORMAT(`date`, '%Y-%m') = ?
");
$stmt->bind_param('is', $studentId, $month);
$stmt->execute();
foreach ($stmt->get_result() as $r) {
    $attMap[$r['date']] = $r['status'];
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Attendance – <?= htmlspecialchars($label) ?></title>

  <!-- Tailwind / Custom CSS (your existing dashboard.css likely includes Tailwind) -->
  <link rel="stylesheet" href="/student-portal/assets/css/dashboard.css">

  <style>
    /* 
      Ensure that any <a class="makeup-btn"> inside the table cell
      displays as a block and has some hover effect.
    */
    .makeup-btn {
      display: block;
      width: 100%;
      height: 100%;
      text-decoration: none;
      color: inherit;
    }
    .makeup-btn:hover {
      background-color: rgba(255,255,255,0.1);
    }
  </style>
</head>
<body class="bg-gray-100">
  <div class="max-w-4xl mx-auto py-6">

    <!-- Page Header -->
    <h1 class="text-3xl font-bold text-gray-800 mb-4">Attendance for <?= htmlspecialchars($label) ?></h1>

    <!-- Month navigation -->
    <div class="flex items-center mb-4 space-x-2">
      <a href="?page=attendance&month=<?= $prev ?>"
         class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">&larr; Prev</a>
      <a href="?page=attendance&month=<?= $next ?>"
         class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700">Next &rarr;</a>
      <span class="ml-4 text-lg font-medium"><?= htmlspecialchars($label) ?></span>
    </div>

    <!-- Calendar Table -->
    <div class="overflow-x-auto">
      <table class="w-full table-auto bg-gray-800 text-white rounded-md">
        <thead>
          <tr>
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
              <th class="px-2 py-1 border border-gray-700"><?= $wd ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($weeks as $week): ?>
            <tr>
              <?php foreach ($week as $d): ?>
                <?php if (is_null($d)): ?>
                  <!-- Empty cell (padding before month starts or after month ends) -->
                  <td class="h-20 border border-gray-700"></td>
                <?php else:
                  // Build the YYYY-MM-DD string for this day
                  $dayStr = $dt->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
                  $status = $attMap[$dayStr] ?? null;
                  $bg     = '';
                  if ($status === 'Present') {
                    $bg = 'bg-green-600';
                  } elseif ($status === 'Absent') {
                    $bg = 'bg-red-600';
                  } elseif ($status === 'Compensation') {
                    $bg = 'bg-yellow-500';
                  }
                ?>
                  <?php if ($status === 'Absent'): ?>
                    <!-- Absent cell → entire cell is a “Make-Up” button linking to compensation.php -->
                    <td class="h-20 border border-gray-700 relative <?= $bg ?>">
                      <a
                        href="/student-portal/dashboard/student/compensation.php?absent_date=<?= $dayStr ?>"
                        class="makeup-btn"
                        title="Click to request a make-up for <?= $dayStr ?>"
                      >
                        <div class="text-sm"><?= $d ?></div>
                        <div class="absolute bottom-1 right-1 text-xs font-bold">A</div>
                        <div class="absolute top-1 left-1 px-1 text-xs bg-white text-red-600 rounded">
                          Make-Up
                        </div>
                      </a>
                    </td>
                  <?php else: ?>
                    <!-- Non-absent day: just show the number and a tiny status letter if any -->
                    <td class="h-20 border border-gray-700 relative <?= $bg ?>">
                      <div class="text-sm"><?= $d ?></div>
                      <?php if ($status): ?>
                        <div class="absolute bottom-1 right-1 text-xs font-bold">
                          <?= substr($status, 0, 1) ?>
                        </div>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Legend -->
    <div class="mt-6 flex space-x-4">
      <div class="flex items-center space-x-1">
        <span class="inline-block w-4 h-4 bg-green-600"></span>
        <span class="text-gray-700">Present</span>
      </div>
      <div class="flex items-center space-x-1">
        <span class="inline-block w-4 h-4 bg-red-600"></span>
        <span class="text-gray-700">Absent (clickable)</span>
      </div>
      <div class="flex items-center space-x-1">
        <span class="inline-block w-4 h-4 bg-yellow-500"></span>
        <span class="text-gray-700">Compensation</span>
      </div>
    </div>

  </div>
</body>
</html>
