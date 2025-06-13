<?php
// File: dashboard/admin/attendance.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── Generate CSRF token for the “Save” form ───────────────────────
$csrf = generate_csrf_token();

// ─── Handle POST (save attendance for the selected day) ────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    die('Invalid CSRF token.');
  }
  $centre_id    = (int)$_POST['centre_id'];
  $selectedDate = $_POST['selected_date'];

  // Delete old records for that centre & date
  $del = $conn->prepare("
    DELETE a
      FROM attendance a
      JOIN students s ON s.id = a.student_id
     WHERE s.centre_id = ? AND a.`date` = ?
  ");
  $del->bind_param('is', $centre_id, $selectedDate);
  $del->execute();
  $del->close();

  // Insert new ones
  $ins = $conn->prepare("
    INSERT INTO attendance(student_id, `date`, status)
    VALUES (?, ?, ?)
  ");
  foreach ($_POST['status'] as $stu_id => $st) {
    if (! in_array($st, ['Present','Absent','Compensation'], true)) continue;
    $ins->bind_param('iss', $stu_id, $selectedDate, $st);
    $ins->execute();
  }
  $ins->close();

  // Redirect back to avoid resubmission
  header("Location:?page=attendance&centre_id={$centre_id}&month=".urlencode($_POST['month'])."&selected_date=".urlencode($selectedDate));
  exit;
}

// ─── Build month navigator ─────────────────────────────────────────
$month = $_GET['month'] ?? date('Y-m');
try {
  $dt = new DateTime("{$month}-01");
} catch (Throwable $e) {
  $dt = new DateTime();
  $month = $dt->format('Y-m');
}
$prev  = (clone $dt)->modify('-1 month')->format('Y-m');
$next  = (clone $dt)->modify('+1 month')->format('Y-m');
$label = $dt->format('F Y');
$days  = (int)$dt->format('t');
$start = (int)$dt->format('w');

// build calendar cells
$cells = array_pad([], $start, null);
for ($d=1; $d<=$days; $d++) $cells[] = $d;
while(count($cells)%7) $cells[] = null;
$weeks = array_chunk($cells, 7);

// ─── Load centres & students ───────────────────────────────────────
$centreList = $conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$centre_id  = isset($_GET['centre_id']) ? (int)$_GET['centre_id'] : ($centreList[0]['id'] ?? 0);

$stmt = $conn->prepare("SELECT id,name FROM students WHERE centre_id=? ORDER BY name");
$stmt->bind_param('i',$centre_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedDate = $_GET['selected_date'] ?? date('Y-m-d');

// ─── Fetch monthly statuses ────────────────────────────────────────
$monthStatuses = [];
$stmt = $conn->prepare("
  SELECT a.`date`,a.status
    FROM attendance a
    JOIN students s ON s.id = a.student_id
   WHERE s.centre_id=? AND DATE_FORMAT(a.`date`,'%Y-%m')=?
");
$stmt->bind_param('is',$centre_id,$month);
$stmt->execute();
foreach($stmt->get_result() as $r){
  $monthStatuses[$r['date']] = $r['status'];
}
$stmt->close();

// ─── Fetch selected‐day statuses ──────────────────────────────────
$dayStatuses = [];
if ($selectedDate) {
  $ids = array_column($students,'id') ?: [0];
  $in  = implode(',',array_map('intval',$ids));
  $stmt = $conn->prepare("
    SELECT student_id,status
      FROM attendance
     WHERE `date`=? AND student_id IN({$in})
  ");
  $stmt->bind_param('s',$selectedDate);
  $stmt->execute();
  foreach($stmt->get_result() as $r){
    $dayStatuses[$r['student_id']] = $r['status'];
  }
  $stmt->close();
}

// ─── Year‐to‐date attendance counts ───────────────────────────────
$thisYear = date('Y');
$countStmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM attendance 
   WHERE student_id=? 
     AND status IN('Present','Compensation') 
     AND YEAR(`date`)=?
");
$ytd = [];
foreach($students as $s){
  $countStmt->bind_param('ii',$s['id'],$thisYear);
  $countStmt->execute();
  $countStmt->bind_result($n);
  $countStmt->fetch();
  $ytd[$s['id']] = $n;
}
$countStmt->close();
?>
<div class="container mx-auto px-4 py-6 space-y-6">

  <!-- Month / Centre / Date nav -->
  <form method="get" class="flex flex-wrap items-center gap-2 text-sm">
    <input type="hidden" name="page" value="attendance">
    <input type="hidden" name="selected_date" value="<?=htmlspecialchars($selectedDate)?>">
    <button type="submit" name="month" value="<?=$prev?>"
            class="px-2 py-1 bg-gray-200 rounded hover:bg-gray-300">←</button>
    <button type="submit" name="month" value="<?=$next?>"
            class="px-2 py-1 bg-gray-200 rounded hover:bg-gray-300">→</button>

    <span class="mx-2 font-semibold"><?=htmlspecialchars($label)?></span>

    <select name="centre_id"
            class="border rounded px-2 py-1"
            onchange="this.form.submit()">
      <?php foreach($centreList as $c): ?>
        <option value="<?=$c['id']?>"
          <?= $c['id']===$centre_id ? 'selected':'' ?>>
          <?=htmlspecialchars($c['name'])?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="month"
            class="border rounded px-2 py-1"
            onchange="this.form.submit()">
      <?php for($m=1;$m<=12;$m++):
        $dt->setDate((int)$dt->format('Y'),$m,1);
        $val = $dt->format('Y-m');
      ?>
        <option value="<?=$val?>"
          <?= $val===$month?'selected':''?>>
          <?=$dt->format('M Y')?>
        </option>
      <?php endfor; ?>
    </select>
  </form>

  <!-- Calendar Grid -->
  <div class="overflow-x-auto bg-white rounded-lg shadow">
    <table class="min-w-full text-center text-sm">
      <thead class="bg-gray-100">
        <tr>
          <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
            <th class="p-2 font-medium text-gray-600"><?=$wd?></th>
          <?php endforeach;?>
        </tr>
      </thead>
      <tbody>
        <?php foreach($weeks as $week): ?>
          <tr>
            <?php foreach($week as $d): ?>
              <?php if($d===null): ?>
                <td class="p-2"></td>
              <?php else:
                $dayStr = $dt->format('Y-m-').str_pad($d,2,'0',STR_PAD_LEFT);
                $cellCls = $dayStr===$selectedDate
                          ? 'bg-purple-100' : '';
                $st = $monthStatuses[$dayStr] ?? '';
                $dot = '';
                if ($st==='Present')      $dot='bg-green-500';
                if ($st==='Absent')       $dot='bg-red-500';
                if ($st==='Compensation') $dot='bg-yellow-300';
              ?>
                <td class="relative p-2 <?=$cellCls?>">
                  <span><?=$d?></span>
                  <?php if($st): ?>
                    <span class="absolute bottom-1 right-1 w-4 h-4 rounded-full <?=$dot?>"></span>
                  <?php endif;?>
                </td>
              <?php endif;?>
            <?php endforeach;?>
          </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>

  <!-- Attendance form for selected day -->
  <div class="bg-white rounded-lg shadow p-6">
    <h2 class="text-lg font-semibold mb-4">Attendance on <?=htmlspecialchars($selectedDate)?></h2>
    <form method="post" class="space-y-4">
      <input type="hidden" name="csrf_token"    value="<?=$csrf?>">
      <input type="hidden" name="centre_id"      value="<?=$centre_id?>">
      <input type="hidden" name="month"          value="<?=htmlspecialchars($month)?>">
      <input type="hidden" name="selected_date"  value="<?=htmlspecialchars($selectedDate)?>">

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-gray-50">
            <tr>
              <th class="p-2 text-left">Student</th>
              <th class="p-2 text-center">Taken YTD</th>
              <th class="p-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($students as $s): 
              $cur = $dayStatuses[$s['id']] ?? '';
            ?>
              <tr class="border-t">
                <td class="p-2"><?=htmlspecialchars($s['name'])?></td>
                <td class="p-2 text-center"><?=$ytd[$s['id']]?></td>
                <td class="p-2">
                  <select name="status[<?=$s['id']?>]"
                          class="w-full border rounded p-1"
                  >
                    <option value="">–</option>
                    <option value="Present"      <?=$cur==='Present'      ? 'selected':''?>>Present</option>
                    <option value="Absent"       <?=$cur==='Absent'       ? 'selected':''?>>Absent</option>
                    <option value="Compensation" <?=$cur==='Compensation' ? 'selected':''?>>Compensation</option>
                  </select>
                </td>
              </tr>
            <?php endforeach;?>
          </tbody>
        </table>
      </div>

      <button
        type="submit"
        class="mt-4 w-full bg-purple-600 text-white py-2 rounded-lg hover:bg-purple-700 transition"
      >Save for <?=htmlspecialchars($selectedDate)?></button>
    </form>
  </div>

</div>
