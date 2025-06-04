<?php
// File: dashboard/admin/attendance.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── Generate CSRF token for the “Save” form ───────────────────────
$csrf = generate_csrf_token();

// ─── Handle POST (save attendance for the selected day) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    die('Invalid CSRF');
  }
  $c = (int)$_POST['centre_id'];
  $d = $_POST['selected_date'];

  // delete old records for that centre & date
  $del = $conn->prepare("
    DELETE a
      FROM attendance a
      JOIN students s ON s.id = a.student_id
     WHERE s.centre_id = ? AND a.`date` = ?
  ");
  $del->bind_param('is', $c, $d);
  $del->execute();

  // insert new ones
  $ins = $conn->prepare("
    INSERT INTO attendance(student_id, `date`, status)
    VALUES (?, ?, ?)
  ");
  foreach ($_POST['status'] as $stu => $st) {
    if (! in_array($st, ['Present','Absent','Compensation'], true)) {
      continue;
    }
    $ins->bind_param('iss', $stu, $d, $st);
    $ins->execute();
  }

  header("Location:?page=attendance&centre_id=$c&month=".urlencode($_POST['month'])."&selected_date=".urlencode($d));
  exit;
}

// ─── Build the month navigation variables ────────────────────────────
$month = $_GET['month'] ?? date('Y-m');
try {
  $dt = new DateTime("$month-01");
} catch (Exception $e) {
  $dt    = new DateTime();
  $month = $dt->format('Y-m');
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
while (count($cells) % 7) {
  $cells[] = null;
}
$weeks = array_chunk($cells, 7);

// ─── Load centres & their students ──────────────────────────────────
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$centre_id = isset($_GET['centre_id'])
           ? (int)$_GET['centre_id']
           : ((int)($centres[0]['id'] ?? 0));

$stmt = $conn->prepare("SELECT id,name FROM students WHERE centre_id = ? ORDER BY name");
$stmt->bind_param('i', $centre_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedDate = $_GET['selected_date'] ?? date('Y-m-d');

// ─── Fetch attendance statuses for the entire month ────────────────
$ms = [];
$stmt = $conn->prepare("
  SELECT a.`date`,a.status
    FROM attendance a
    JOIN students s ON s.id = a.student_id
   WHERE s.centre_id = ? AND DATE_FORMAT(a.`date`,'%Y-%m') = ?
");
$stmt->bind_param('is', $centre_id, $month);
$stmt->execute();
foreach ($stmt->get_result() as $r) {
  $ms[$r['date']] = $r['status'];
}
$stmt->close();

// ─── Fetch existing statuses for the selected day ───────────────────
$sel = [];
if ($selectedDate) {
  $ids = array_column($students, 'id') ?: [0];
  $in  = implode(',', array_map('intval', $ids));
  $stmt = $conn->prepare("
    SELECT student_id, status
      FROM attendance
     WHERE `date` = ? AND student_id IN ($in)
  ");
  $stmt->bind_param('s', $selectedDate);
  $stmt->execute();
  foreach ($stmt->get_result() as $x) {
    $sel[$x['student_id']] = $x['status'];
  }
  $stmt->close();
}

// ─── Count “present/comp” this year per student ──────────────────────
$thisYear      = date('Y');
$totalClasses  = 52;
$calc          = $conn->prepare("
  SELECT COUNT(*) FROM attendance
   WHERE student_id = ? AND status IN('Present','Compensation')
     AND YEAR(`date`) = ?
");
$ct = [];
foreach ($students as $s) {
  $calc->bind_param('ii', $s['id'], $thisYear);
  $calc->execute();
  $calc->bind_result($n);
  $calc->fetch();
  $ct[$s['id']] = $n;
}
$calc->close();
?>
<div class="container mx-auto p-4 space-y-6">

  <h2 class="text-2xl font-bold text-gray-800">✍️ Attendance — <?= htmlspecialchars($label) ?></h2>

  <!-- Month nav + Centre + Day selector -->
  <form method="get" class="flex items-center space-x-4">
    <input type="hidden" name="page" value="attendance" />
    <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>" />
    <input type="hidden" name="selected_date" value="<?= htmlspecialchars($selectedDate) ?>" />

    <button type="submit" name="month" value="<?= $prev ?>"
            class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">←</button>
    <button type="submit" name="month" value="<?= $next ?>"
            class="px-3 py-1 bg-gray-200 rounded hover:bg-gray-300">→</button>

    <select name="centre_id"
            class="border border-gray-300 rounded px-3 py-1"
            onchange="this.form.submit()">
      <?php foreach ($centres as $c): 
        $cid = (int)$c['id'];
      ?>
        <option value="<?= $cid ?>"
          <?= $cid === $centre_id ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="selected_date"
            class="border border-gray-300 rounded px-3 py-1"
            onchange="this.form.submit()">
      <?php for ($d = 1; $d <= $days; $d++):
        $dstr = $dt->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
      ?>
        <option value="<?= $dstr ?>"
          <?= $dstr === $selectedDate ? 'selected' : '' ?>>
          <?= $d ?> <?= $dt->format('M') ?>
        </option>
      <?php endfor; ?>
    </select>

    <span class="text-gray-700">Classes/student: <strong><?= $totalClasses ?></strong></span>
  </form>

  <!-- Calendar grid -->
  <div class="overflow-x-auto bg-gray-100 p-2 rounded">
    <table class="min-w-full table-auto">
      <thead>
        <tr class="text-sm font-medium text-gray-700">
          <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
            <th class="p-2"><?= $wd ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($weeks as $week): ?>
          <tr>
            <?php foreach ($week as $d): ?>
              <?php if ($d === null): ?>
                <td class="p-2">&nbsp;</td>
              <?php else:
                $dayStr = $dt->format('Y-m-') . str_pad($d, 2, '0', STR_PAD_LEFT);
                $selCls = $dayStr === $selectedDate ? 'bg-blue-600 text-white' : '';
                $st     = $ms[$dayStr] ?? '';
                $let    = $st ? substr($st, 0, 1) : '';
              ?>
                <td class="<?= $selCls ?> relative p-2">
                  <span><?= $d ?></span>
                  <?php if ($let): ?>
                    <div class="absolute bottom-1 right-1 w-5 h-5 flex items-center justify-center text-xs rounded-full
                                 <?= $st === 'Present'      ? 'bg-green-500 text-white' : '' ?>
                                 <?= $st === 'Absent'       ? 'bg-red-500 text-white'   : '' ?>
                                 <?= $st === 'Compensation' ? 'bg-yellow-300 text-black': '' ?>">
                      <?= $let ?>
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

  <!-- POST form: set attendance for the selected day -->
  <div class="bg-white p-4 rounded shadow">
    <h3 class="font-semibold mb-3">Attendance on <?= htmlspecialchars($selectedDate) ?></h3>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
      <input type="hidden" name="centre_id"     value="<?= $centre_id ?>">
      <input type="hidden" name="month"         value="<?= htmlspecialchars($month) ?>">
      <input type="hidden" name="selected_date" value="<?= htmlspecialchars($selectedDate) ?>">

      <div class="table-responsive">
        <table class="min-w-full border">
          <thead class="bg-gray-200">
            <tr>
              <th class="p-2 text-left">Student</th>
              <th class="p-2 text-center">Taken YTD</th>
              <th class="p-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s):
              $cur = $sel[$s['id']] ?? '';
            ?>
              <tr class="border-t">
                <td class="p-2"><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></td>
                <td class="p-2 text-center"><?= $ct[$s['id']] ?></td>
                <td class="p-2">
                  <select name="status[<?= $s['id'] ?>]"
                          class="w-full border border-gray-300 rounded px-2 py-1 student-status">
                    <option value="">–</option>
                    <option value="Present"      <?= $cur === 'Present'      ? 'selected' : '' ?>>Present</option>
                    <option value="Absent"       <?= $cur === 'Absent'       ? 'selected' : '' ?>>Absent</option>
                    <option value="Compensation" <?= $cur === 'Compensation' ? 'selected' : '' ?>>Compensation</option>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <button class="mt-4 px-4 py-2 bg-admin-primary text-white rounded hover:bg-opacity-90">
        Save for <?= htmlspecialchars($selectedDate) ?>
      </button>
    </form>
  </div>
</div>

<script>
  // Live‐update the dot in the calendar when you change a dropdown
  document.querySelectorAll('.student-status').forEach(sel => {
    sel.addEventListener('change', e => {
      const st   = e.target.value;
      const cell = document.querySelector('td.bg-blue-600');
      if (! cell) return;
      cell.querySelectorAll('.status-dot').forEach(el => el.remove());
      if (st) {
        let d = document.createElement('div');
        d.className = 'absolute bottom-1 right-1 w-5 h-5 flex items-center justify-center text-xs rounded-full ' + (
          st === 'Present'      ? 'bg-green-500 text-white'   :
          st === 'Absent'       ? 'bg-red-500 text-white'     :
          st === 'Compensation' ? 'bg-yellow-300 text-black'  : ''
        );
        d.textContent = st.charAt(0);
        cell.appendChild(d);
      }
    });
  });
</script>
