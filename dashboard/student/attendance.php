<?php
// File: dashboard/student/attendance.php
$page = 'attendance';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = $_SESSION['student_id'];

// ─── Determine month to show (YYYY-MM) ─────────────────────────────
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

// Build calendar days
$days  = (int)$dt->format('t');
$start = (int)$dt->format('w');
$cells = array_pad([], $start, null);
for ($d = 1; $d <= $days; $d++) {
    $cells[] = $d;
}
while (count($cells) % 7) {
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
  <div class="w-full max-w-4xl mx-auto space-y-4">
    <h2 class="text-2xl font-semibold text-gray-800 mb-2"><?= htmlspecialchars($label) ?> Attendance</h2>

    <!-- Month navigation -->
    <div class="flex items-center space-x-2 mb-4">
      <a href="?page=attendance&month=<?= $prev ?>"
         class="btn btn-outline-primary btn-sm">&larr;</a>
      <a href="?page=attendance&month=<?= $next ?>"
         class="btn btn-outline-primary btn-sm">&rarr;</a>
      <span class="ml-4 font-medium"><?= htmlspecialchars($label) ?></span>
    </div>

    <!-- Calendar -->
    <div class="overflow-x-auto calendar bg-gray-800 p-2 rounded">
      <table class="table-auto w-full text-white">
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
                  <td class="h-16 border border-gray-700"></td>
                <?php else: 
                  $dayStr = $dt->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
                  $status = $attMap[$dayStr] ?? null;
                  $bg     = '';
                  if ($status === 'Present')      $bg = 'bg-green-600';
                  elseif ($status === 'Absent')   $bg = 'bg-red-600';
                  elseif ($status === 'Compensation') $bg = 'bg-yellow-500';
                ?>
                  <td class="h-16 border border-gray-700 relative <?= $bg ?>">
                    <div class="text-sm"><?= $d ?></div>
                    <?php if ($status): ?>
                      <div class="absolute bottom-1 right-1 text-xs font-bold">
                        <?= substr($status,0,1) ?>
                      </div>
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
    <div class="mt-4 flex space-x-4">
      <div><span class="inline-block w-4 h-4 bg-green-600"></span> Present</div>
      <div><span class="inline-block w-4 h-4 bg-red-600"></span> Absent</div>
      <div><span class="inline-block w-4 h-4 bg-yellow-500"></span> Compensation</div>
    </div>
  </div>
<?php
