<?php
// File: dashboard/admin/attendance.php

ini_set('display_errors',1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

$csrf = generate_csrf_token();

// ─── Handle POST save ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $centre_id    = (int)$_POST['centre_id'];
    $selectedDate = $_POST['selected_date'];
    $month        = $_POST['month'] ?? date('Y-m');

    // Delete existing
    $del = $conn->prepare("
      DELETE a
        FROM attendance a
        JOIN students s ON s.id=a.student_id
       WHERE s.centre_id=? AND a.date=?
    ");
    $del->bind_param('is',$centre_id,$selectedDate);
    $del->execute();
    $del->close();

    // Insert new
    $ins = $conn->prepare("
      INSERT INTO attendance(student_id,date,status)
      VALUES(?,?,?)
    ");
    foreach($_POST['status'] as $sid=>$st) {
        if (!in_array($st,['Present','Absent','Compensation'],true)) continue;
        $sid=(int)$sid;
        $ins->bind_param('iss',$sid,$selectedDate,$st);
        $ins->execute();
    }
    $ins->close();

    header("Location:?page=attendance"
         ."&centre_id={$centre_id}"
         ."&month=".urlencode($month)
         ."&selected_date=".urlencode($selectedDate));
    exit;
}

// ─── Calendar calculation ────────────────────────────────────────
$month = $_GET['month'] ?? date('Y-m');
try{
    $dt=new DateTime("{$month}-01");
}catch(Throwable){
    $dt=new DateTime();
    $month=$dt->format('Y-m');
}
$prev=(clone$dt)->modify('-1 month')->format('Y-m');
$next=(clone$dt)->modify('+1 month')->format('Y-m');
$label=$dt->format('F Y');
$days=(int)$dt->format('t');
$start=(int)$dt->format('w');

$cells=array_pad([], $start, null);
for($d=1;$d<=$days;$d++) $cells[]=$d;
while(count($cells)%7) $cells[]=null;
$weeks=array_chunk($cells,7);

// ─── Load centres & students ────────────────────────────────────
$centreList=$conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);
$centre_id=isset($_GET['centre_id'])
          ?(int)$_GET['centre_id']
          :($centreList[0]['id']??0);

$stmt=$conn->prepare("SELECT id,name FROM students WHERE centre_id=? ORDER BY name");
$stmt->bind_param('i',$centre_id);
$stmt->execute();
$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Month status dots ──────────────────────────────────────────
$selectedDate=$_GET['selected_date']??date('Y-m-d');
$monthStatuses=[];
$stmt=$conn->prepare("
  SELECT a.date,a.status
    FROM attendance a
    JOIN students s ON s.id=a.student_id
   WHERE s.centre_id=? AND DATE_FORMAT(a.date,'%Y-%m')=?
");
$stmt->bind_param('is',$centre_id,$month);
$stmt->execute();
foreach($stmt->get_result() as $r){
    $monthStatuses[$r['date']]=$r['status'];
}
$stmt->close();

// ─── Day dropdown defaults ──────────────────────────────────────
$dayStatuses=[];
if($selectedDate){
    $ids=array_column($students,'id')?:[0];
    $in=implode(',',$ids);
    $stmt=$conn->prepare("
      SELECT student_id,status
        FROM attendance
       WHERE date=? AND student_id IN({$in})
    ");
    $stmt->bind_param('s',$selectedDate);
    $stmt->execute();
    foreach($stmt->get_result() as $r){
        $dayStatuses[$r['student_id']]=$r['status'];
    }
    $stmt->close();
}

// ─── YTD counts ─────────────────────────────────────────────────
$thisYear=date('Y');
$countStmt=$conn->prepare("
  SELECT COUNT(*) FROM attendance
   WHERE student_id=? 
     AND status IN('Present','Compensation')
     AND YEAR(date)=?
");
$ytd=[];
foreach($students as $s){
    $countStmt->bind_param('ii',$s['id'],$thisYear);
    $countStmt->execute();
    $countStmt->bind_result($n);
    $countStmt->fetch();
    $ytd[$s['id']]=$n;
}
$countStmt->close();
?>

<body class="bg-gray-100 min-h-screen">

  <div class="max-w-5xl mx-auto px-4 py-6 space-y-6">

    <!-- unified nav form -->
    <form id="nav" method="get" class="flex flex-wrap items-center gap-2 text-sm">
      <input type="hidden" name="page"          value="attendance">
      <input type="hidden" name="month"         value="<?=$month?>">
      <input type="hidden" name="selected_date" value="<?=$selectedDate?>">
      <input type="hidden" name="centre_id"     value="<?=$centre_id?>">

      <button type="button" onclick="nav({month:'<?=$prev?>'})"
              class="px-2 py-1 bg-gray-200 rounded">&larr;</button>
      <span class="font-semibold"><?=$label?></span>
      <button type="button" onclick="nav({month:'<?=$next?>'})"
              class="px-2 py-1 bg-gray-200 rounded">&rarr;</button>

      <input type="date" name="selected_date"
             value="<?=$selectedDate?>"
             class="border-gray-300 rounded p-1 text-sm"
             onchange="nav({selected_date:this.value})">

      <select name="centre_id"
              class="border-gray-300 rounded p-1 text-sm"
              onchange="nav({centre_id:this.value})">
        <?php foreach($centreList as $c): ?>
          <option value="<?=$c['id']?>"
            <?=$c['id']==$centre_id?'selected':''?>>
            <?=$c['name']?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
    <script>
      function nav(ch){
        const f=document.getElementById('nav');
        Object.entries(ch).forEach(([k,v])=>{
          f.querySelector(`[name="${k}"]`).value=v;
        });
        f.submit();
      }
    </script>

    <!-- calendar -->
    <div class="overflow-auto bg-white rounded-lg shadow">
      <table class="w-full text-center text-sm">
        <thead class="bg-gray-100">
          <tr><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd):?>
            <th class="p-2 font-medium text-gray-600"><?=$wd?></th>
          <?php endforeach;?></tr>
        </thead>
        <tbody>
          <?php foreach($weeks as $week):?>
            <tr>
              <?php foreach($week as $d): ?>
                <?php if(is_null($d)):?>
                  <td class="p-2"></td>
                <?php else:
                  $dayStr=sprintf('%s-%02d',$month,$d);
                  $sel=$dayStr===$selectedDate?'bg-purple-100':'';
                  $dot=match($monthStatuses[$dayStr]??''){
                    'Present'=>'bg-green-500',
                    'Absent'=>'bg-red-500',
                    'Compensation'=>'bg-yellow-300',
                    default=>'',
                  };
                ?>
                  <td class="relative p-2 <?=$sel?>">
                    <a href="javascript:nav({selected_date:'<?=$dayStr?>'})"
                       class="block"><?=$d?>
                      <?php if($dot):?>
                        <span class="absolute bottom-1 right-1 w-3 h-3 rounded-full <?=$dot?>"></span>
                      <?php endif;?>
                    </a>
                  </td>
                <?php endif;?>
              <?php endforeach;?>
            </tr>
          <?php endforeach;?>
        </tbody>
      </table>
    </div>

    <!-- bulk & filter -->
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="flex gap-2 w-full sm:w-auto">
        <button type="button" class="bg-green-600 text-white px-4 py-2 rounded"
                onclick="document.querySelectorAll('.status-select')
                                 .forEach(el=>el.value='Present')">
          Mark All Present
        </button>
        <button type="button" class="bg-red-600 text-white px-4 py-2 rounded"
                onclick="document.querySelectorAll('.status-select')
                                 .forEach(el=>el.value='Absent')">
          Mark All Absent
        </button>
        <button type="button" class="bg-yellow-500 text-white px-4 py-2 rounded"
                onclick="document.querySelectorAll('.status-select')
                                 .forEach(el=>el.value='Compensation')">
          Mark All Comp
        </button>
      </div>
      <input id="search" type="text" placeholder="Search student…"
             class="border rounded px-2 py-1 flex-1 sm:flex-none text-sm"
             oninput="document.querySelectorAll('#stuTable tbody tr')
                       .forEach(r=>{
                         const t=r.querySelector('td').textContent.toLowerCase();
                         r.style.display = t.includes(this.value.toLowerCase()) ? '' : 'none';
                       })">
    </div>

    <!-- attendance form -->
    <div class="bg-white rounded-lg shadow p-6 overflow-x-auto">
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token"   value="<?=$csrf?>">
        <input type="hidden" name="centre_id"     value="<?=$centre_id?>">
        <input type="hidden" name="selected_date" value="<?=$selectedDate?>">
        <input type="hidden" name="month"         value="<?=$month?>">

        <div class="text-gray-700 font-semibold">
          Attendance on <?=$selectedDate?>
        </div>

        <table id="stuTable" class="w-full text-sm border-collapse">
          <thead>
            <tr class="bg-gray-50">
              <th class="p-2 text-left">Student</th>
              <th class="p-2 text-center">YTD</th>
              <th class="p-2 text-left">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($students as $s):
              $cur=$dayStatuses[$s['id']]??'';
            ?>
              <tr class="border-t">
                <td class="p-2"><?=$s['name']?></td>
                <td class="p-2 text-center"><?=$ytd[$s['id']]?></td>
                <td class="p-2">
                  <select name="status[<?=$s['id']?>]"
                          class="status-select w-full border-gray-300 rounded p-1 text-sm">
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

        <button type="submit"
                class="mt-4 w-full bg-purple-600 text-white py-2 rounded-lg">
          Save Attendance
        </button>
      </form>
    </div>
  </div>
</body>
</html>
