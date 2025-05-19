<?php
// ─── 0) Buffer & Bootstrap ───────────────────────────────────
if (!ob_get_level()) ob_start();
require_once __DIR__.'/../../config/session.php';
require_once __DIR__.'/../../config/db.php';

// ─── 1) Admin guard ──────────────────────────────────────────
if (empty($_SESSION['logged_in'])||($_SESSION['role']??'')!=='admin') {
  header('Location: ../../login.php');exit;
}

// ─── 2) CSRF token ───────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ─── 3) Handle Save (POST) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']??'')) {
    die('Invalid CSRF');
  }
  $centre_id    = (int)$_POST['centre_id'];
  $selectedDate = $_POST['selected_date'];

  // delete old
  $del = $conn->prepare("
    DELETE a
      FROM attendance a
      JOIN students s ON s.id=a.student_id
     WHERE s.centre_id=? AND a.`date`=?
  ");
  $del->bind_param('is',$centre_id,$selectedDate);
  $del->execute();

  // insert new
  $ins = $conn->prepare("
    INSERT INTO attendance(student_id,`date`,status)
    VALUES(?,?,?)
  ");
  foreach ($_POST['status'] as $stu=>$st) {
    if (!in_array($st,['Present','Absent','Compensation'],true)) continue;
    $ins->bind_param('iss',$stu,$selectedDate,$st);
    $ins->execute();
  }

  header("Location:?page=attendance"
      ."&centre_id={$centre_id}"
      ."&month=".urlencode($_POST['month'])
      ."&selected_date=".urlencode($selectedDate));
  exit;
}

// ─── 4) Month & nav context ──────────────────────────────────
$month = $_GET['month']??date('Y-m');
try { $dt=new DateTime("$month-01"); }
catch(Exception$e){ $dt=new DateTime(); $month=$dt->format('Y-m'); }
$prev  =(clone $dt)->modify('-1 month')->format('Y-m');
$next  =(clone $dt)->modify('+1 month')->format('Y-m');
$label =$dt->format('F Y');
$days  =(int)$dt->format('t');
$start =(int)$dt->format('w');
$cells = array_pad([], $start, null);
for($d=1;$d<=$days;$d++) $cells[]=$d;
while(count($cells)%7) $cells[]=null;
$weeks = array_chunk($cells,7);

// ─── 5) Centres & students ──────────────────────────────────
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);
$centre_id = isset($_GET['centre_id'])
           ? (int)$_GET['centre_id']
           : ($centres[0]['id']??0);

$stmt = $conn->prepare("
  SELECT id,name FROM students
   WHERE centre_id=? ORDER BY name
");
$stmt->bind_param('i',$centre_id);
$stmt->execute();
$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── 6) Selected date ───────────────────────────────────────
$selectedDate = $_GET['selected_date'] ?? $dt->format('Y-m-01');

// ─── 7) Month’s status map ──────────────────────────────────
$ms = [];
$stmt = $conn->prepare("
  SELECT a.`date`,a.status
    FROM attendance a JOIN students s ON s.id=a.student_id
   WHERE s.centre_id=? AND DATE_FORMAT(a.`date`,'%Y-%m')=?
");
$stmt->bind_param('is',$centre_id,$month);
$stmt->execute();
$res=$stmt->get_result();
while($r=$res->fetch_assoc()) {
  $ms[$r['date']]=$r['status'];
}
$stmt->close();

// ─── 8) Selected-date statuses ──────────────────────────────
$sel = [];
if ($selectedDate) {
  $ids = array_column($students,'id')?:[0];
  $in  = implode(',',array_map('intval',$ids));
  $stmt = $conn->prepare("
    SELECT student_id,status FROM attendance
     WHERE `date`=? AND student_id IN($in)
  ");
  $stmt->bind_param('s',$selectedDate);
  $stmt->execute();
  $r = $stmt->get_result();
  while($x=$r->fetch_assoc()) $sel[$x['student_id']]=$x['status'];
  $stmt->close();
}

// ─── 9) Taken this year + total classes ─────────────────────
$thisYear= date('Y');
$totalClasses=52;
$calc = $conn->prepare("
  SELECT COUNT(*) FROM attendance
   WHERE student_id=? AND status IN('Present','Compensation')
     AND YEAR(`date`)=?
");
$ct=[];
foreach($students as $s){
  $calc->bind_param('is',$s['id'],$thisYear);
  $calc->execute();
  $calc->bind_result($n); $calc->fetch();
  $ct[$s['id']]=$n;
}
$calc->close();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Attendance — <?=$label?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
      rel="stylesheet">
<style>
body{background:#1f1f2f;color:#fff;}
.hdr-row{background:#2d2d44;color:#fff;}
.hdr-row h2{margin:0;font-size:1.5rem;}
.hdr-row .btn{font-size:.9rem;}
.hdr-row .form-select-sm{min-width:120px;}
.calendar{background:#202033;padding:.5rem;overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th,td{border:1px solid #2a2a3d;text-align:center;padding:.5rem;}
th{background:#2d2d44;}
td{background:#202033;position:relative;}
td:hover{background:#2f2f46;}
.selected-day{background:#3a3a5a!important;}
.status-dot{position:absolute;bottom:4px;right:4px;width:18px;height:18px;
  border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:.75rem;color:#fff;}
.status-dot.Present{background:#28a745;}
.status-dot.Absent{background:#dc3545;}
.status-dot.Compensation{background:#ffc107;color:#000;}
.form-area{background:#2d2d44;padding:1rem;margin-top:1rem;border-radius:4px;}
.bottom{background:#2d2d44;padding:1rem;text-align:center;font-size:.9rem;}
@media(max-width:576px){
  .hdr-row h2{font-size:1.25rem;}
  th,td{padding:.4rem;font-size:.75rem;}
}
</style>
</head><body>
<div class="container-fluid p-0">

  <!-- unified GET form: month nav + centre + day -->
  <div class="hdr-row row align-items-center py-2 px-3">
    <form class="d-flex align-items-center w-100" method="get">
      <input type="hidden" name="page"           value="attendance">
      <input type="hidden" name="centre_id"      value="<?=$centre_id?>">
      <input type="hidden" name="selected_date"  value="<?=htmlspecialchars($selectedDate)?>">
      <input type="hidden" name="month"          value="<?=htmlspecialchars($month)?>">

      <div class="col-auto">
        <button type="submit" name="month" value="<?=$prev?>"
                class="btn btn-outline-light btn-sm me-2">←</button>
        <button type="submit" name="month" value="<?=$next?>"
                class="btn btn-outline-light btn-sm">→</button>
      </div>

      <div class="col text-center">
        <h2 class="mb-0"><?=htmlspecialchars($label)?></h2>
      </div>

      <div class="col-auto d-flex align-items-center">
        <select name="centre_id" class="form-select form-select-sm me-2"
                onchange="this.form.submit()">
          <?php foreach($centres as $c): ?>
            <option value="<?=$c['id']?>" <?=$c['id']===$centre_id?'selected':''?>>
              <?=htmlspecialchars($c['name'])?>
            </option>
          <?php endforeach;?>
        </select>

        <select name="selected_date" class="form-select form-select-sm me-3"
                onchange="this.form.submit()">
          <?php for($d=1;$d<=$days;$d++):
            $dstr=$dt->format('Y-m-').str_pad($d,2,'0',STR_PAD_LEFT);
          ?>
            <option value="<?=$dstr?>" <?=$dstr===$selectedDate?'selected':''?>>
              <?=$d?> <?=$dt->format('M')?>
            </option>
          <?php endfor;?>
        </select>

        <span>Classes/student: <strong><?=$totalClasses?></strong></span>
      </div>
    </form>
  </div>

  <!-- calendar with P/A/C -->
  <div class="calendar mb-2">
    <table>
      <thead><tr>
        <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd):?>
          <th><?=$wd?></th>
        <?php endforeach;?>
      </tr></thead>
      <tbody>
      <?php foreach($weeks as $week):?><tr>
        <?php foreach($week as $d):?>
          <?php if($d===null):?><td></td>
          <?php else:
            $dstr  = $dt->format('Y-m-').str_pad($d,2,'0',STR_PAD_LEFT);
            $sel   = $dstr=== $selectedDate?' selected-day':'';
            $st    = $ms[$dstr]??'';
            $let   = $st?substr($st,0,1):'';
          ?>
            <td class="<?=$sel?>" data-date="<?=$dstr?>">
              <div><?=$d?></div>
              <?php if($let):?>
                <div class="status-dot <?=$st?>"><?=$let?></div>
              <?php endif;?>
            </td>
          <?php endif;?>
        <?php endforeach;?>
      </tr><?php endforeach;?>
      </tbody>
    </table>
  </div>

  <!-- per-day student list -->
  <div class="form-area">
    <h5>Attendance on <?=htmlspecialchars($selectedDate)?></h5>
    <form method="post" id="attForm">
      <input type="hidden" name="csrf_token"    value="<?=htmlspecialchars($_SESSION['csrf_token'])?>">
      <input type="hidden" name="centre_id"     value="<?=$centre_id?>">
      <input type="hidden" name="month"         value="<?=$month?>">
      <input type="hidden" name="selected_date" value="<?=$selectedDate?>">

      <div class="table-responsive mb-3">
        <table class="table table-dark table-striped">
          <thead><tr>
            <th>Student</th>
            <th>Taken This Year</th>
            <th>Status</th>
          </tr></thead>
          <tbody>
          <?php foreach($students as $s):
            $cur = $sel[$s['id']] ?? '';
          ?>
            <tr>
              <td><?=htmlspecialchars($s['name'])?></td>
              <td><?=$ct[$s['id']]?></td>
              <td>
                <select name="status[<?=$s['id']?>]"
                        class="form-select form-select-sm student-status">
                  <option value="">–</option>
                  <option value="Present"      <?=$cur==='Present'?'selected':''?>>Present</option>
                  <option value="Absent"       <?=$cur==='Absent'?'selected':''?>>Absent</option>
                  <option value="Compensation" <?=$cur==='Compensation'?'selected':''?>>Compensation</option>
                </select>
              </td>
            </tr>
          <?php endforeach;?>
          </tbody>
        </table>
      </div>
      <button class="btn btn-primary">Save for <?=$selectedDate?></button>
    </form>
  </div>

  <!-- bottom legend -->
  <div class="bottom mt-2">
    <span class="text-success">🟢 Present</span> |
    <span class="text-danger">🔴 Absent</span> |
    <span class="text-warning">🟡 Compensation</span>
  </div>

</div>

<script>
// When you change any student-status dropdown,
// immediately update the calendar cell’s dot.
document.querySelectorAll('.student-status').forEach(sel=>{
  sel.addEventListener('change', e=>{
    const status = e.target.value;
    const dstr   = '<?= $selectedDate ?>';
    const cell   = document.querySelector(`td[data-date="${dstr}"]`);
    if (!cell) return;
    // remove old dot
    cell.querySelectorAll('.status-dot').forEach(el=>el.remove());
    if (status) {
      const dot = document.createElement('div');
      dot.className = 'status-dot '+status;
      dot.textContent = status.charAt(0);
      cell.appendChild(dot);
    }
  });
});
</script>
</body></html>
<?php ob_end_flush(); ?>
