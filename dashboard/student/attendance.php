<?php
// File: dashboard/student/attendance.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = intval($_SESSION['student_id'] ?? 0);

$month = $_GET['month'] ?? date('Y-m');
$showWarning = false;
if ($month < '2025-06') {
    $showWarning = true;
    $month = '2025-06';
}

try {
    $dt = new DateTime("{$month}-01");
} catch (Exception $e) {
    $dt = new DateTime('2025-06-01');
    $month = '2025-06';
}
$prev  = (clone $dt)->modify('-1 month')->format('Y-m');
$next  = (clone $dt)->modify('+1 month')->format('Y-m');
$label = $dt->format('F Y');

$days  = (int)$dt->format('t');
$start = (int)$dt->format('w');
$cells = array_pad([], $start, null);
for ($d = 1; $d <= $days; $d++) {
    $cells[] = $d;
}
while (count($cells) % 7 !== 0) {
    $cells[] = null;
}
$weeks = array_chunk($cells, 7);

$attMap = [];
$stmt = $conn->prepare("SELECT `date`,`status` FROM attendance WHERE student_id = ? AND DATE_FORMAT(`date`,'%Y-%m') = ?");
$stmt->bind_param('is', $studentId, $month);
$stmt->execute();
foreach ($stmt->get_result() as $r) {
    $attMap[$r['date']] = $r['status'];
}
$stmt->close();

// Attendance Trends
$totalPresent = 0;
$totalAbsent = 0;
$totalComp = 0;
$streak = 0;
$maxStreak = 0;
$daysAttended = [];
$weeklyStats = [];

foreach ($attMap as $date => $status) {
    $week = date('W', strtotime($date));
    $weeklyStats[$week]['total'] = ($weeklyStats[$week]['total'] ?? 0) + 1;

    if ($status === 'Present' || $status === 'Compensation') {
        $streak++;
        $daysAttended[] = $date;
        $weeklyStats[$week]['attended'] = ($weeklyStats[$week]['attended'] ?? 0) + 1;
        if ($status === 'Present') $totalPresent++;
        if ($status === 'Compensation') $totalComp++;
    } elseif ($status === 'Absent') {
        $totalAbsent++;
        if ($streak > $maxStreak) $maxStreak = $streak;
        $streak = 0;
    }
}
if ($streak > $maxStreak) $maxStreak = $streak;

$totalDays = $totalPresent + $totalAbsent + $totalComp;
$attendancePercent = $totalDays > 0 ? round((($totalPresent + $totalComp) / $totalDays) * 100) : 0;
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
<style>
  .leading-tight { line-height: 0.0 !important; }
  .font-bold    { font-weight: 500!important; }
  .text-\[8px\] { font-size: 5px!important; }
  .attendance-code { font-weight: bold; font-size: 0.75rem; color: #111 !important; }
</style>

<div class="container-fluid px-4 py-6">
  <div class="flex flex-wrap items-center justify-between mb-4 gap-2">
    <div class="flex space-x-2">
      <a href="?page=attendance&month=<?= $prev ?>" class="px-2 sm:px-4 py-1 bg-student-primary text-white rounded hover:bg-student-primary/90 transition">
        <span class="hidden sm:inline">&larr; Prev</span>
        <span class="sm:hidden">&larr;</span>
      </a>
      <a href="?page=attendance&month=<?= $next ?>" class="px-2 sm:px-4 py-1 bg-student-primary text-white rounded hover:bg-student-primary/90 transition">
        <span class="hidden sm:inline">Next &rarr;</span>
        <span class="sm:hidden">&rarr;</span>
      </a>
    </div>
    <h2 class="text-lg sm:text-xl font-semibold"><?= htmlspecialchars($label) ?></h2>
  </div>

  <?php if ($showWarning): ?>
    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded text-sm">
      <strong>Note:</strong> Attendance records are only available from <strong>June 2025</strong> onward.
    </div>
  <?php else: ?>
    <div class="bg-white rounded shadow overflow-hidden">
      <table class="w-full table-fixed text-center text-xs sm:text-sm">
        <thead class="bg-student-secondary text-white">
          <tr>
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
              <th class="px-1 py-1 sm:px-2 sm:py-2"><?= $wd ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($weeks as $week): ?>
            <tr>
              <?php foreach ($week as $d): ?>
                <?php if (is_null($d)): ?>
                  <td class="h-12 sm:h-16 border">&nbsp;</td>
                <?php else:
                  $dayStr = $dt->format('Y-m-') . str_pad($d,2,'0',STR_PAD_LEFT);
                  $status = $attMap[$dayStr] ?? null;
                  $bg = $status === 'Present'
                        ? 'bg-green-300'
                        : ($status === 'Absent'
                            ? 'bg-red-300'
                            : ($status === 'Compensation'
                                ? 'bg-yellow-300'
                                : ''
                              )
                          );
                ?>
                  <td class="border <?= $bg ?> relative px-1 sm:px-2 py-1 sm:py-2">
                    <?php if ($status === 'Absent'): ?>
                      <a href="?page=compensation&absent_date=<?= $dayStr ?>" class="absolute inset-0 flex flex-col justify-between p-1 sm:p-2" title="Request make-up for <?= $dayStr ?>">
                        <span class="text-xs sm:text-sm"><?= $d ?></span>
                        <span class="self-end text-[8px] sm:text-xs font-bold bg-white text-red-600 px-1 rounded">Make-Up</span>
                      </a>
                    <?php else: ?>
                      <div class="text-xs sm:text-sm"><?= $d ?></div>
                      <?php if ($status): ?>
                        <div class="absolute bottom-1 right-1 attendance-code">
                          <?= htmlspecialchars(substr($status,0,1)) ?>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="mt-6 grid grid-cols-1 sm:grid-cols-4 gap-4 text-sm text-center">
      <div class="bg-green-100 text-green-800 rounded p-2">
        ✅ Present: <strong><?= $totalPresent ?></strong>
      </div>
      <div class="bg-red-100 text-red-800 rounded p-2">
        ❌ Absent: <strong><?= $totalAbsent ?></strong>
      </div>
      <div class="bg-yellow-100 text-yellow-800 rounded p-2">
        🔁 Compensation: <strong><?= $totalComp ?></strong>
      </div>
      <div class="bg-blue-100 text-blue-800 rounded p-2">
        📊 Attendance: <strong><?= $attendancePercent ?>%</strong>
      </div>
    </div>

    <div class="mt-4 text-center text-sm text-purple-700 font-medium">
      🔥 Current Streak: <strong><?= $streak ?> days</strong> &nbsp;|&nbsp; 🏅 Max Streak: <strong><?= $maxStreak ?> days</strong>
    </div>

    <div class="mt-6 text-sm">
      <h3 class="font-semibold mb-2">📅 Weekly Summary</h3>
      <ul class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
        <?php foreach ($weeklyStats as $week => $data): ?>
          <li class="bg-white border rounded shadow-sm p-2">
            <strong>Week <?= $week ?>:</strong><br/>
            <?= $data['attended'] ?? 0 ?> / <?= $data['total'] ?> attended
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="mt-6 grid grid-cols-1 sm:flex sm:space-x-6 text-xs sm:text-sm">
    <div class="flex items-center mb-2 sm:mb-0">
      <span class="w-3 h-3 inline-block mr-1" style="background-color:#28a745;"></span>
      Present
    </div>
    <div class="flex items-center mb-2 sm:mb-0">
      <span class="w-3 h-3 inline-block mr-1" style="background-color:#dc3545;"></span>
      Absent
    </div>
    <div class="flex items-center">
      <span class="w-3 h-3 inline-block mr-1" style="background-color:#d4af37;"></span>
      Compensation
    </div>
  </div>
</div>
