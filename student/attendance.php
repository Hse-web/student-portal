<?php
// File: dashboard/student/attendance.php
$page = 'attendance';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = intval($_SESSION['student_id'] ?? 0);

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

// Build calendar cells
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

// Fetch attendance statuses
$attMap = [];
$stmt = $conn->prepare("
  SELECT `date`,`status`
    FROM attendance
   WHERE student_id = ?
     AND DATE_FORMAT(`date`,'%Y-%m') = ?
");
$stmt->bind_param('is', $studentId, $month);
$stmt->execute();
foreach ($stmt->get_result() as $r) {
    $attMap[$r['date']] = $r['status'];
}
$stmt->close();
?>
<style>
  .leading-tight {
    line-height: 0.0 !important;
}

.font-bold {
    font-weight: 500!important;
}
.text-\[8px\] {
    font-size: 5px!important;
}
</style>
<div class="container-fluid px-4 py-6">

  <!-- Month navigation -->
  <div class="flex flex-wrap items-center justify-between mb-4 gap-2">
    <div class="flex space-x-2">
      <!-- on xs show arrows only, from sm up show full text -->
      <a href="?page=attendance&month=<?= $prev ?>"
         class="px-2 sm:px-4 py-1 bg-student-primary text-white rounded hover:bg-student-primary/90 transition">
        <span class="hidden sm:inline">&larr; Prev</span>
        <span class="sm:hidden">&larr;</span>
      </a>
      <a href="?page=attendance&month=<?= $next ?>"
         class="px-2 sm:px-4 py-1 bg-student-primary text-white rounded hover:bg-student-primary/90 transition">
        <span class="hidden sm:inline">Next &rarr;</span>
        <span class="sm:hidden">&rarr;</span>
      </a>
    </div>
    <h2 class="text-lg sm:text-xl font-semibold"><?= htmlspecialchars($label) ?></h2>
  </div>

  <!-- Calendar -->
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
                      ? 'bg-green-100'
                      : ($status === 'Absent'
                          ? 'bg-red-100'
                          : ($status === 'Compensation'
                              ? 'bg-yellow-100'
                              : ''
                            )
                        );
              ?>
                <td class="border <?= $bg ?> relative px-1 sm:px-2 py-1 sm:py-2">
                  <?php if ($status === 'Absent'): ?>
                    <a href="/artovue/dashboard/student/compensation.php?absent_date=<?= $dayStr ?>"
                       class="absolute inset-0 flex flex-col justify-between p-1 sm:p-2"
                       title="Request make-up for <?= $dayStr ?>">
                      <span class="text-xs sm:text-sm"><?= $d ?></span>
                      <span class="self-end text-[8px] sm:text-xs font-bold bg-white text-red-600 px-1 rounded">
                        Make-Up
                      </span>
                    </a>
                  <?php else: ?>
                    <div class="text-xs sm:text-sm"><?= $d ?></div>
                    <?php if ($status): ?>
                      <div class="absolute bottom-1 right-1 text-[8px] sm:text-xs font-bold">
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

  <!-- Legend -->
  <div class="mt-6 grid grid-cols-1 sm:flex sm:space-x-6 text-xs sm:text-sm">
    <div class="flex items-center mb-2 sm:mb-0">
      <span class="w-3 h-3 bg-green-600 inline-block mr-1"></span>Present
    </div>
    <div class="flex items-center mb-2 sm:mb-0">
      <span class="w-3 h-3 bg-red-600 inline-block mr-1"></span>Absent
    </div>
    <div class="flex items-center">
      <span class="w-3 h-3 bg-yellow-500 inline-block mr-1"></span>Compensation
    </div>
  </div>

</div>
