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
date_default_timezone_set('Asia/Kolkata');
$studentId = intval($_SESSION['student_id'] ?? 0);

// 1) Fetch student name
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

// 2) Current & next group
$studentGroup = get_current_group_label($conn, $studentId);
$nextGroup    = get_next_group_label($conn, $studentId);

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

// 4) Pull last 6 months of progress rows
$limit = 6;
$sqlCols = 'month';
foreach ($cats as $k=>$_) {
  $sqlCols .= ", {$k}, {$remarkCols[$k]}";
}
$stmt = $conn->prepare("
  SELECT {$sqlCols}
    FROM progress
   WHERE student_id = ?
   ORDER BY month DESC
   LIMIT ?
");
$stmt->bind_param('ii', $studentId, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5) Ensure at least this month placeholder
$thisMonth = $rows[0] ?? array_merge(
  ['month'=>date('Y-m')],
  array_fill_keys(array_keys($cats),0),
  array_fill_keys(array_values($remarkCols),'')
);

// helpers for chart data
$codeToPct = [0,20,40,60,80,100];
function pctFor(array $rec, string $key, array $map) {
  return $map[intval($rec[$key] ?? 0)] ?? 0;
}
function emojiFor(int $pct): string {
  if ($pct >= 80) return '😃';
  if ($pct >= 60) return '🙂';
  if ($pct >= 40) return '😐';
  return '😢';
}

// build datasets
$monthly = [];
foreach ($cats as $k=>$_) {
  $monthly[$k] = pctFor($thisMonth, $k, $codeToPct);
}
$quarter = [];
$slice = array_slice($rows,0,3);
foreach ($cats as $k=>$_) {
  $avg = $slice
    ? round(array_sum(array_column($slice,$k)) / count($slice))
    : 0;
  $quarter[$k] = $codeToPct[$avg] ?? 0;
}
$half = [];
foreach ($cats as $k=>$_) {
  $avg = $rows
    ? round(array_sum(array_column($rows,$k)) / count($rows))
    : 0;
  $half[$k] = $codeToPct[$avg] ?? 0;
}

// remark codes
$remarks = [];
foreach ($cats as $k=>$_) {
  $remarks[$k] = intval($thisMonth[$k] ?? 0);
}

// Art Journey data
$current=null; $next=null;
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
if($q->fetch()) $current=$tmp;
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
if($q->fetch()) $next=$tmp;
$q->close();

$groups = $conn
  ->query("SELECT id,label FROM art_groups ORDER BY sort_order")
  ->fetch_all(MYSQLI_ASSOC);

$currentIdx = array_search($current, array_column($groups,'id'));
$nextIdx    = array_search($next,    array_column($groups,'id'));

$journey = [];
foreach ($groups as $i=>$g) {
  if ($currentIdx!==false && $i<$currentIdx)     $st='completed';
  elseif ($i===$currentIdx)                     $st='current';
  elseif ($i===$nextIdx)                        $st='upcoming';
  else                                           $st='locked';
  $journey[] = ['label'=>$g['label'],'status'=>$st];
}

$map = [
  'completed'=>['✅','green'],
  'current'  =>['✏️','blue'],
  'upcoming' =>['🎨','amber'],
  'locked'   =>['🔒','gray'],
];
?>
<head>
  <meta charset="utf-8">
  <title>My Progress – Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Tailwind palette -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme:{extend:{
        colors:{
          primary:'#6b21a8',
          secondary:'#9333ea',
          accent:'#f59e0b'
        }
      }}
    };
  </script>
  <!-- Bootstrap & Icons -->
  <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"-->
  <!--      rel="stylesheet"/>-->
  <!--<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"-->
  <!--      rel="stylesheet"/>-->
  <style>
    .bg-primary {
      background:linear-gradient(135deg,#9333ea,#6b21a8)!important;
    }
    .card:hover {
      transform:scale(1.02);
      box-shadow:0 10px 20px rgba(0,0,0,0.12);
    }
  </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors">

  <main class="p-6">
    <!-- Art Journey with wavy background -->
    <section class="relative bg-white rounded-2xl shadow-xl mb-8 p-6 overflow-visible">
      <svg class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none" aria-hidden="true">
        <path d="M100,150 C250,0 400,200 550,50 S800,200 900,100"
              stroke="#E5E7EB" stroke-width="4" fill="none"/>
      </svg>

      <h3 class="relative text-3xl font-extrabold text-center mb-4">Art Journey</h3>
      <div class="relative z-10 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <?php foreach($journey as $step):
          list($emoji,$clr) = $map[$step['status']];
        ?>
          <div
            class="p-4 rounded-lg border-2 border-<?= $clr ?>-200 bg-<?= $clr ?>-50 flex flex-col items-center"
            data-bs-toggle="tooltip"
            title="<?= ucfirst($step['status']) ?>"
          >
            <div class="text-4xl"><?= $emoji ?></div>
            <div class="mt-2"><?= htmlspecialchars($step['label']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- Greeting + stage info -->
    <?php
      $h = (int)date('H');
      $g = $h<12?'Good morning':($h<16?'Good afternoon':'Good evening');
    ?>
    <h1 class="text-2xl font-bold mb-1"><?= $g ?>, <?= htmlspecialchars($studentName) ?>!</h1>
    <p class="text-gray-600 mb-4">
      Stage: <strong><?= htmlspecialchars($studentGroup) ?></strong>
      <?php if ($nextGroup): ?>→ Next: <strong><?= htmlspecialchars($nextGroup) ?></strong><?php endif; ?>
    </p>

    <!-- Download + filter -->
    <div class="flex justify-between items-center mb-6">
      <a href="download_progress.php?range=monthly"
         class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download"></i> Report Card
      </a>
      <select id="rangeSelect" class="form-select w-auto">
        <option value="monthly">This Month</option>
        <option value="quarter" <?= count($rows)<3?'disabled':'' ?>>Last 3 Mo.</option>
        <option value="half"    <?= count($rows)<6?'disabled':'' ?>>Last 6 Mo.</option>
      </select>
    </div>

    <!-- Overall -->
    <div class="card mb-6 shadow-md">
      <div class="card-body text-center">
        <h5><i class="bi bi-award-fill text-secondary"></i> Overall Performance</h5>
        <div class="w-full bg-gray-200 h-3 rounded-full overflow-hidden mb-2">
          <div id="overallBar" class="h-3 bg-gradient-to-r from-purple-600 to-indigo-500" style="width:0;"></div>
        </div>
        <p id="overallText" class="h4"></p>
        <div id="overallEmoji" class="text-2xl"></div>
      </div>
    </div>

    <!-- Metrics grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach($cats as $key=>$label): ?>
        <div class="card p-4 shadow-md text-center" id="<?= $key ?>-card">
          <h6 class="mb-2"><?= htmlspecialchars($label) ?></h6>
          <div class="relative mx-auto my-3" style="width:100px;height:100px">
            <canvas id="chart-<?= $key ?>" width="100" height="100"></canvas>
            <div class="absolute inset-0 flex items-center justify-center text-xl">
              <span id="emoji-<?= $key ?>"></span>
            </div>
          </div>
          <p class="text-sm text-gray-600"><?= htmlspecialchars($gradeLabels[$remarks[$key]] ?? '') ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </main>

  <!-- Footer from bootstrap.php assumed here -->

  <!-- Scripts: Bootstrap, Chart.js, plus tooltip init -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    // Enable all tooltips
    document.querySelectorAll('[data-bs-toggle="tooltip"]')
      .forEach(el => new bootstrap.Tooltip(el));

    // Chart data and helpers
    const cats = <?= json_encode(array_keys($cats)) ?>;
    const dataSets = {
      monthly: <?= json_encode(array_values($monthly)) ?>,
      quarter: <?= json_encode(array_values($quarter)) ?>,
      half:    <?= json_encode(array_values($half)) ?>
    };
    const colorFor = p => p >= 50 ? 'rgba(33,150,243,0.7)' : 'rgba(244,67,54,0.7)';
    const emojiFor = p => p >= 80 ? '😃' : p >= 60 ? '🙂' : p >= 40 ? '😐' : '😢';

    // Initialize doughnuts
    const charts = {};
    cats.forEach((c,i) => {
      const ctx = document.getElementById('chart-'+c).getContext('2d');
      charts[c] = new Chart(ctx, {
        type:'doughnut',
        data:{
          datasets:[{
            data:[dataSets.monthly[i],100-dataSets.monthly[i]],
            backgroundColor:[colorFor(dataSets.monthly[i]),'#eee'],
            cutout:'75%'
          }]
        },
        options:{plugins:{legend:{display:false}},responsive:false}
      });
      document.getElementById('emoji-'+c).textContent = emojiFor(dataSets.monthly[i]);
    });

    // Update overall bar & text
    const overallBar = document.getElementById('overallBar');
    const overallText = document.getElementById('overallText');
    const overallEmoji= document.getElementById('overallEmoji');
    function updateOverall(arr){
      const avg = Math.round(arr.reduce((a,b)=>a+b,0)/arr.length);
      overallBar.style.width = avg + '%';
      overallText.textContent  = avg>=80?'Excellent':avg>=60?'Good':avg>=40?'Average':'Needs Improvement';
      overallEmoji.textContent = emojiFor(avg);
    }
    updateOverall(dataSets.monthly);

    // Range select handler
    document.getElementById('rangeSelect').onchange = e => {
      const r = e.target.value;
      cats.forEach((c,i) => {
        const val = dataSets[r][i], ch = charts[c];
        ch.data.datasets[0].data             = [val,100-val];
        ch.data.datasets[0].backgroundColor[0]= colorFor(val);
        ch.update({duration:800});
        document.getElementById('emoji-'+c).textContent = emojiFor(val);
      });
      updateOverall(dataSets[r]);
      document.querySelector('a[href^="download_progress.php"]')
        .href = `download_progress.php?range=${r}`;
    };
  });
  </script>
</body>
</html>
