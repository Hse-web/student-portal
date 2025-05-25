<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
require_role('student');

$studentId = (int)$_SESSION['student_id'];

// Get centre_id
$stmt = $conn->prepare("SELECT centre_id FROM students WHERE id=?");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$stmt->bind_result($centreId);
$stmt->fetch();
$stmt->close();

// Determine month
$month = $_GET['month'] ?? date('Y-m');
try { $dt = new DateTime("$month-01"); }
catch (Exception) { $dt = new DateTime(); $month = $dt->format('Y-m'); }
$prev = (clone $dt)->modify('-1 month');
$next = (clone $dt)->modify('+1 month');
$label = $dt->format('F Y');

// Fetch attendance
$stmt = $conn->prepare("SELECT date,status FROM attendance WHERE student_id=? AND DATE_FORMAT(date,'%Y-%m')=?");
$stmt->bind_param('is',$studentId,$month);
$stmt->execute();
$res = $stmt->get_result();
$att = [];
while($r=$res->fetch_assoc()){ $att[$r['date']]=$r['status']; }
$stmt->close();

// Summary counts
$total = count($att);
$present = count(array_filter($att, fn($s)=>$s==='Present'));
$absent  = count(array_filter($att, fn($s)=>$s==='Absent'));$comp    = count(array_filter($att, fn($s)=>$s==='Compensation'));

// Calendar params
$firstDow = (int)$dt->format('w');
$days = (int)$dt->format('t');
?>
<!DOCTYPE html>
<html lang="en"><head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Attendance – <?=htmlspecialchars($label)?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <style>
    body{background:#1e1e2f;color:#ddd;}
    .topbar,.legend{background:#2b2b3f;}
    .summary{background:#27273b;color:#fff;padding:.75rem;}
    .cal-cell{height:100px;vertical-align:top;}
    .status-dot{width:1.5rem;height:1.5rem;border-radius:50%;display:inline-block;text-align:center;line-height:1.5rem;margin-top:.25rem;color:#fff;}
    .present{background:#28a745;}.absent{background:#dc3545;}.compensation{background:#ffc107;color:#212529;}
  </style>
</head><body>
<div class="container py-4">
  <div class="topbar rounded-top d-flex justify-content-between text-light mb-0">
    <a href="?month=<?=$prev->format('Y-m')?>" class="btn btn-sm btn-outline-light"><i class="bi bi-chevron-left"></i></a>
    <h4 class="m-0"><?=htmlspecialchars($label)?></h4>
    <a href="?month=<?=$next->format('Y-m')?>" class="btn btn-sm btn-outline-light"><i class="bi bi-chevron-right"></i></a>
  </div>
  <div class="summary">
    <strong>Total:</strong> <?=$total?> |
    <span class="text-success"><i class="bi bi-check-circle-fill"></i> <?=$present?></span> |
    <span class="text-danger"><i class="bi bi-x-circle-fill"></i> <?=$absent?></span> |
    <span class="text-warning"><i class="bi bi-clock-history"></i> <?=$comp?></span>
  </div>
  <div class="legend text-center p-2 mb-4">
    <button class="btn btn-sm present"><i class="bi bi-check-circle"></i> Present</button>
    <button class="btn btn-sm absent"><i class="bi bi-x-circle"></i> Absent</button>
    <button class="btn btn-sm compensation"><i class="bi bi-clock-history"></i> Compensation</button>
  </div>
  <div class="table-responsive">
    <table class="table table-dark table-bordered text-center rounded-bottom">
      <thead><tr>
        <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th>
        <th>Thu</th><th>Fri</th><th>Sat</th>
      </tr></thead>
      <tbody><?php
      $day=1;
      for($r=0;$r<6;$r++){
        echo '<tr>';
        for($d=0;$d<7;$d++){
          if(($r===0&&$d<$firstDow)||$day>$days){
            echo '<td class="cal-cell bg-dark"></td>';
          } else {
            $key = $dt->format('Y-m-') . str_pad($day,2,'0',STR_PAD_LEFT);
            $st  = $att[$key]??'';
            $cls = match($st){'Present'=>'present','Absent'=>'absent','Compensation'=>'compensation',default=>''};
            echo '<td class="cal-cell">';
            echo '<div class="date">'.$day.'</div>';
            if($cls) echo '<div class="status-dot '.$cls.'">'.strtoupper($st[0]).'</div>';
            if($st==='Absent'&&$centreId!==3){
              echo '<div class="mt-2"><a href="video_compensation.php?date='.urlencode($key).'" class="btn btn-warning btn-sm">Watch Video</a></div>';
            }
            echo '</td>';
            $day++;
          }
        }
        echo '</tr>';
        if($day>$days) break;
      }
      ?></tbody>
    </table>
  </div>
</div>
</body></html>