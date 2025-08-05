<?php
// File: dashboard/student/progress.php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

$page = 'progress';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../../config/db.php';
date_default_timezone_set('Asia/Kolkata');

$studentId = intval($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
  header('Location:/artovue/login.php');
  exit;
}

// 1) Student name
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// 2) Current & next group
$studentGroup = get_current_group_label($conn, $studentId);
$nextGroup    = get_next_group_label   ($conn, $studentId);

// 3) Categories & remark columns
$cats = [
  'hand_control'     => 'Hand Control',
  'coloring_shading' => 'Coloring & Shading',
  'observations'     => 'Observations',
  'temperament'      => 'Temperament',
  'attendance'       => 'Attendance',
  'homework'         => 'Homework',
];
$remarkCols = [
  'hand_control'     => 'hc_remark',
  'coloring_shading' => 'cs_remark',
  'observations'     => 'obs_remark',
  'temperament'      => 'temp_remark',
  'attendance'       => 'att_remark',
  'homework'         => 'hw_remark',
];
$gradeLabels = [
  1 => 'Needs Improvement',
  2 => 'Average',
  3 => 'Good',
  4 => 'Very Good',
  5 => 'Excellent',
];

// 4) Build last-6-months list & read query
$allMonths = [];
for ($i = 0; $i < 6; $i++) {
  $allMonths[] = (new DateTime("-{$i} months"))->format('Y-m');
}
$selectedMonth = $_GET['month'] ?? date('Y-m');
if (!in_array($selectedMonth, $allMonths, true)) {
  $selectedMonth = date('Y-m');
}

// 5) Range buttons (1 mo / 3 mo / 6 mo)
$range      = $_GET['range'] ?? 'monthly';
$monthsBack = match($range) {
  '3mo','quarter' => 3,
  '6mo','half'    => 6,
  default         => 1,
};

// 6) Pull up to 6 rows of progress
$sqlCols = 'month';
foreach ($cats as $k=>$_) {
  $sqlCols .= ", {$k}, {$remarkCols[$k]}";
}
$stmt = $conn->prepare("
  SELECT {$sqlCols}
    FROM progress
   WHERE student_id = ?
   ORDER BY month DESC
   LIMIT 6
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 7) Ensure selected month is present
$found = false;
foreach ($rows as $r) {
  if ($r['month'] === $selectedMonth) {
    $found = true;
    break;
  }
}
if (!$found) {
  array_unshift($rows, array_merge(
    ['month'=>$selectedMonth],
    array_fill_keys(array_keys($cats),0),
    array_fill_keys(array_values($remarkCols),'')
  ));
  $rows = array_slice($rows, 0, 6);
}

// ——— NEW “only bail on 3-mo/6-mo” guard ———
$start = (new DateTime($selectedMonth.'-01'))
          ->modify("-".($monthsBack-1)." months")
          ->modify('first day of');
$monthFrom = $start->format('Y-m');
$countStmt = $conn->prepare("
  SELECT COUNT(*) AS cnt
    FROM progress
   WHERE student_id = ?
     AND month BETWEEN ? AND ?
");
$countStmt->bind_param('iss', $studentId, $monthFrom, $selectedMonth);
$countStmt->execute();
$count = (int)$countStmt->get_result()->fetch_assoc()['cnt'];
$countStmt->close();

if ($monthsBack > 1 && $count < $monthsBack) {
  $label = $monthsBack === 3 ? '3-month' : '6-month';
  echo '<div class="container py-5">';
  echo   '<div class="alert alert-warning">';
  echo     "No progress available for the “{$label}” period yet. We record progress every month, so please check back once you have {$monthsBack} months of data.";
  echo   '</div>';
  echo '</div>';
  exit;
}

// 8) Helpers for chart data
$codeToPct = [0,20,40,60,80,100];
function pctFor(array $rec, string $key, array $map): int {
  return $map[intval($rec[$key] ?? 0)] ?? 0;
}
function emojiFor(int $pct): string {
  if ($pct >= 80) return '😃';
  if ($pct >= 60) return '🙂';
  if ($pct >= 40) return '😐';
  return '😢';
}

// 9) Build monthly/quarter/half arrays + remarks
$thisRow = array_filter($rows, fn($r)=>$r['month']===$selectedMonth)[0] ?? $rows[0];
$slice3  = array_slice($rows,0,3);
$monthly = $quarter = $half = $remarks = [];
foreach ($cats as $k=>$_) {
  $monthly[$k] = pctFor($thisRow, $k, $codeToPct);
  $avg3 = $slice3
    ? round(array_sum(array_column($slice3,$k))/count($slice3))
    : 0;
  $quarter[$k] = $codeToPct[$avg3] ?? 0;
  $avg6 = round(array_sum(array_column($rows,$k))/count($rows));
  $half[$k] = $codeToPct[$avg6] ?? 0;
  $remarks[$k] = intval($thisRow[$k] ?? 0);
}
// 10) Art Journey steps
$current = $next = null;
$q = $conn->prepare("
  SELECT art_group_id
    FROM student_promotions
   WHERE student_id=? AND is_applied=1
   ORDER BY effective_date DESC
   LIMIT 1
");
$q->bind_param('i',$studentId);
$q->execute();
$q->bind_result($tmp);
if($q->fetch()) $current = $tmp;
$q->close();

$q = $conn->prepare("
  SELECT art_group_id
    FROM student_promotions
   WHERE student_id=? AND is_applied=0
   ORDER BY effective_date ASC
   LIMIT 1
");
$q->bind_param('i',$studentId);
$q->execute();
$q->bind_result($tmp);
if($q->fetch()) $next = $tmp;
$q->close();

$groups = $conn->query("SELECT id,label FROM art_groups ORDER BY sort_order")
              ->fetch_all(MYSQLI_ASSOC);
$currentIdx = array_search($current, array_column($groups,'id'));
$nextIdx    = array_search($next,    array_column($groups,'id'));

$journey = [];
foreach ($groups as $i=>$g) {
  if ($currentIdx!==false && $i<$currentIdx)      $st='completed';
  elseif ($i===$currentIdx)                      $st='current';
  elseif ($i===$nextIdx)                         $st='upcoming';
  else                                           $st='locked';
  $journey[] = ['label'=>$g['label'],'status'=>$st];
}

$map = [
  'completed'=>['✅','green'],
  'current'  =>['✏️','blue'],
  'upcoming' =>['🎨','amber'],
  'locked'   =>['🔒','gray'],
];

$flash = get_flash();
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Progress – Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .bg-primary    {background:linear-gradient(135deg,#9333ea,#6b21a8)!important;}
    .card-hover:hover {transform:scale(1.02);box-shadow:0 10px 20px rgba(0,0,0,0.12);}
    .art-journey svg {
      position:absolute;
      z-index:-1;
      top:0; left:0;
      width:100%; height:100%;
    }
  </style>
</head>
<body class="bg-gray-50">

  <main class="container py-5">

    <?php if($flash): ?>
      <div class="alert alert-<?= $flash['type']==='danger'?'danger':'success' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>
    <h3>
      Stage: <strong><?= htmlspecialchars($studentGroup) ?></strong>
      <?php if($nextGroup): ?>
       → Next: <strong><?= htmlspecialchars($nextGroup) ?></strong>
      <?php endif; ?>
    </h3>

    <!-- Month + Range + Download -->
    <div class="d-flex align-items-end mb-4">
      <form method="get" class="d-flex align-items-end">
        <input type="hidden" name="page" value="progress">
        <div class="me-3">
          <label class="form-label">Month</label>
          <select name="month" class="form-select"
                  onchange="this.form.submit()">
            <?php foreach($allMonths as $m): ?>
              <option value="<?=$m?>" <?=$m===$selectedMonth?'selected':''?>>
                <?= (new DateTime($m.'-01'))->format('F Y') ?>
              </option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="me-3">
          <label class="form-label">&nbsp;</label>
          <div class="btn-group">
            <button type="submit" name="range" value="monthly"
                    class="btn <?= $monthsBack===1?'btn-primary':'btn-outline-secondary' ?>">
              1 mo
            </button>
            <button type="submit" name="range" value="quarter"
                    class="btn <?= $monthsBack===3?'btn-primary':'btn-outline-secondary' ?>">
              3 mo
            </button>
            <button type="submit" name="range" value="half"
                    class="btn <?= $monthsBack===6?'btn-primary':'btn-outline-secondary' ?>">
              6 mo
            </button>
          </div>
        </div>
      </form>
      <a href="download_progress.php?range=<?=urlencode($range)?>&month=<?=urlencode($selectedMonth)?>"
         class="btn btn-success ms-auto">
        <i class="bi bi-download"></i> Report Card
      </a>
    </div>

  <!-- ART JOURNEY -->
    <section class="position-relative bg-white rounded shadow p-4 mb-5 art-journey">
      <h3 class="text-center mb-4">Art Journey</h3>
      <div class="row gy-3">
        <?php foreach($journey as $step):
          list($emoji,$clr) = $map[$step['status']];
          $extra = $step['status']==='current'
                 ? ' border-primary bg-primary-10'
                 : ($step['status']==='upcoming'
                    ? ' border-accent bg-accent-10'
                    : '');
        ?>
          <div class="col-6 col-md-4 col-lg-2">
            <div class="p-3 border border-<?= $clr ?><?= $extra ?> rounded text-center">
              <div class="fs-2"><?= $emoji ?></div>
              <div><?= htmlspecialchars($step['label']) ?></div>
            </div>
          </div>
        <?php endforeach;?>
      </div>
      <svg viewBox="0 0 1000 200" preserveAspectRatio="none" aria-hidden="true">
        <path d="M100,150 C250,0 400,200 550,50 S800,200 900,100"
              stroke="#E5E7EB" stroke-width="4" fill="none"/>
      </svg>
    </section>

    <!-- Overall Performance -->
    <div class="card mb-4">
      <div class="card-body text-center">
        <h5><i class="bi bi-award-fill text-secondary"></i> Overall Performance</h5>
        <div class="progress my-3" style="height:1.5rem">
          <div id="overallBar" class="progress-bar bg-primary" style="width:0%"></div>
        </div>
        <div id="overallText" class="fw-bold"></div>
        <div id="overallEmoji" class="fs-1"></div>
      </div>
    </div>

    <!-- Metrics Grid -->
    <div class="row g-4">
      <?php foreach($cats as $key=>$label): ?>
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card-hover card p-3 text-center">
            <h6><?= htmlspecialchars($label) ?></h6>
            <div class="position-relative mx-auto my-3"
                 style="width:100px;height:100px">
              <canvas id="chart-<?= $key ?>" width="100" height="100"></canvas>
              <div class="position-absolute top-50 start-50 translate-middle fs-3"
                   id="emoji-<?= $key ?>"></div>
            </div>
            <div class="text-muted">
              <?= htmlspecialchars($gradeLabels[$remarks[$key]] ?? '') ?>
            </div>
          </div>
        </div>
      <?php endforeach;?>
    </div>

  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    const cats      = <?= json_encode(array_keys($cats)) ?>;
    const monthly   = <?= json_encode(array_values($monthly)) ?>;
    const quarter   = <?= json_encode(array_values($quarter)) ?>;
    const half      = <?= json_encode(array_values($half)) ?>;
    const dataSets  = { monthly, quarter, half };
    const colorFor  = p => p>=50 ? 'rgba(33,150,243,0.7)' : 'rgba(244,67,54,0.7)';
    const emojiFor  = p => p>=80 ? '😃' : p>=60 ? '🙂' : p>=40 ? '😐' : '😢';

    // Render each chart + emoji
    cats.forEach((key,i) => {
      const val = dataSets.monthly[i];
      const ctx = document.getElementById(`chart-${key}`).getContext('2d');
      new Chart(ctx, {
        type:'doughnut',
        data:{ datasets:[{
          data:[val,100-val],
          backgroundColor:[colorFor(val),'#eee'],
          cutout:'75%'
        }]},
        options:{plugins:{legend:{display:false}},responsive:false}
      });
      document.getElementById(`emoji-${key}`).textContent = emojiFor(val);
    });

    // Overall
    const avg = Math.round(monthly.reduce((a,b)=>a+b,0)/cats.length);
    document.getElementById('overallBar').style.width = avg + '%';
    document.getElementById('overallText').textContent =
      avg>=80?'Excellent':avg>=60?'Good':avg>=40?'Average':'Needs Improvement';
    document.getElementById('overallEmoji').textContent = emojiFor(avg);
  });
  </script>
</body>
</html>
