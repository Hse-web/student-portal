<?php
// dashboard/admin/attendance.php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1) CSRF for your POST “Save” form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// 2) Handle POST save (only when you click “Save for …”)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF');
    }
    $c = (int) $_POST['centre_id'];
    $d = $_POST['selected_date'];

    // delete old for that centre + day
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
      VALUES(?,?,?)
    ");
    foreach ($_POST['status'] as $stu => $st) {
        if (!in_array($st, ['Present','Absent','Compensation'], true)) continue;
        $ins->bind_param('iss', $stu, $d, $st);
        $ins->execute();
    }

    header("Location:?page=attendance"
         ."&centre_id={$c}"
         ."&month=".urlencode($_POST['month'])
         ."&selected_date=".urlencode($d));
    exit;
}

// 3) Build month‐nav context
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
for ($d = 1; $d <= $days; $d++) $cells[] = $d;
while (count($cells) % 7) $cells[] = null;
$weeks = array_chunk($cells, 7);

// 4) Centres & students
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$centre_id = isset($_GET['centre_id'])
           ? (int)$_GET['centre_id']
           : ((int)($centres[0]['id'] ?? 0));

// fetch students for that centre
$stmt = $conn->prepare("SELECT id,name FROM students WHERE centre_id=? ORDER BY name");
$stmt->bind_param('i', $centre_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5) Selected date: use GET if set, otherwise default to today
$selectedDate = $_GET['selected_date'] 
              ?? date('Y-m-d');

// 6) Month’s status map (for the dots)
$ms = [];
$stmt = $conn->prepare("
  SELECT a.`date`,a.status
    FROM attendance a
    JOIN students s ON s.id=a.student_id
   WHERE s.centre_id=? AND DATE_FORMAT(a.`date`,'%Y-%m')=?
");
$stmt->bind_param('is', $centre_id, $month);
$stmt->execute();
foreach ($stmt->get_result() as $r) {
  $ms[$r['date']] = $r['status'];
}
$stmt->close();

// 7) Statuses on the selected day
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

// 8) Count “taken” this year per student
$thisYear = date('Y');
$totalClasses = 52;
$calc = $conn->prepare("
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
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Attendance — <?= $label ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <style>
    body{background:#1f1f2f;color:#fff;}
    .hdr-row{background:#2d2d44;color:#fff;padding:.75rem;}
    .calendar{background:#202033;padding:.5rem;overflow-x:auto;}
    th,td{border:1px solid #2a2a3d;text-align:center;padding:.5rem;}
    .selected-day{background:#3a3a5a!important;}
    .status-dot{position:absolute;bottom:4px;right:4px;width:18px;height:18px;
      border-radius:50%;display:flex;align-items:center;justify-content:center;
      font-size:.75rem;color:#fff;}
    .status-dot.Present{background:#28a745;}
    .status-dot.Absent{background:#dc3545;}
    .status-dot.Compensation{background:#ffc107;color:#000;}
    .form-area{background:#2d2d44;padding:1rem;margin-top:1rem;border-radius:4px;}
  </style>
</head>
<body>
<div class="container-fluid p-0">

  <!-- GET form: month nav + centre + day -->
  <div class="hdr-row d-flex align-items-center">
    <form method="get" class="d-flex w-100">
      <input type="hidden" name="page"          value="attendance">
      <input type="hidden" name="month"         value="<?= htmlspecialchars($month) ?>">
      <input type="hidden" name="selected_date" value="<?= htmlspecialchars($selectedDate) ?>">

      <button type="submit" name="month" value="<?= $prev ?>"
              class="btn btn-outline-light btn-sm me-2">←</button>
      <button type="submit" name="month" value="<?= $next ?>"
              class="btn btn-outline-light btn-sm me-4">→</button>

      <!-- centre selector (fixed) -->
      <select name="centre_id"
              class="form-select form-select-sm mx-3"
              onchange="this.form.submit()">
        <?php foreach ($centres as $c):
          $cid = (int) $c['id'];  // cast to int
        ?>
          <option value="<?= $cid ?>"
            <?= $cid === $centre_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <!-- day selector -->
      <select name="selected_date" class="form-select form-select-sm me-3"
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

      <div>Classes/student: <strong><?= $totalClasses ?></strong></div>
    </form>
  </div>

  <!-- calendar -->
  <div class="calendar mb-3">
    <table class="table table-dark mb-0"><thead><tr>
      <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
        <th><?= $wd ?></th>
      <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($weeks as $week): ?><tr>
      <?php foreach ($week as $d): ?>
        <?php if ($d === null): ?><td></td>
        <?php else:
          $dayStr = $dt->format('Y-m-') . str_pad($d,2,'0',STR_PAD_LEFT);
          $selCls = $dayStr === $selectedDate ? ' selected-day' : '';
          $st     = $ms[$dayStr] ?? '';
          $let    = $st ? substr($st,0,1) : '';
        ?>
          <td class="<?= $selCls ?>" style="position:relative">
            <?= $d ?>
            <?php if ($let): ?>
            <div class="status-dot <?= $st ?>"><?= $let ?></div>
            <?php endif; ?>
          </td>
        <?php endif; ?>
      <?php endforeach; ?>
    </tr><?php endforeach; ?>
    </tbody></table>
  </div>

  <!-- POST form to save only -->
  <div class="form-area">
    <h5>Attendance on <?= $selectedDate ?></h5>
    <form method="post">
      <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
      <input type="hidden" name="centre_id"     value="<?= $centre_id ?>">
      <input type="hidden" name="month"         value="<?= $month ?>">
      <input type="hidden" name="selected_date" value="<?= $selectedDate ?>">

      <div class="table-responsive mb-3">
        <table class="table table-dark table-striped mb-0">
          <thead><tr>
            <th>Student</th><th>Taken This Year</th><th>Status</th>
          </tr></thead>
          <tbody>
          <?php foreach ($students as $s):
            $cur = $sel[$s['id']] ?? '';
          ?>
            <tr>
              <td><?= htmlspecialchars($s['name'], ENT_QUOTES) ?></td>
              <td><?= $ct[$s['id']] ?></td>
              <td>
                <select name="status[<?= $s['id'] ?>]"
                        class="form-select form-select-sm student-status">
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
      <button class="btn btn-primary">Save for <?= $selectedDate ?></button>
    </form>
  </div>
</div>

<script>
// live‐update the dot in the calendar when you change a dropdown
document.querySelectorAll('.student-status').forEach(sel => {
  sel.addEventListener('change', e => {
    const st   = e.target.value,
          cell = document.querySelector('td.selected-day');
    if (!cell) return;
    cell.querySelectorAll('.status-dot').forEach(el => el.remove());
    if (st) {
      const d = document.createElement('div');
      d.className = 'status-dot ' + st;
      d.textContent = st.charAt(0);
      cell.appendChild(d);
    }
  });
});
</script>
</body>
</html>
