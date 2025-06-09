<?php
// File: dashboard/student/progress.php

$page = 'progress';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = intval($_SESSION['student_id']);

// 1) Fetch student info
$stmt = $conn->prepare("SELECT name, group_name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName, $studentGroup);
$stmt->fetch();
$stmt->close();

// 2) Define categories & remark columns
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
// grade labels
$gradeLabels = [
  1 => 'Needs Improvement',
  2 => 'Average',
  3 => 'Good',
  4 => 'Very Good',
  5 => 'Excellent',
];

// 3) Fetch last 6 months of progress
$limit = 6;
$sqlCols = 'month';
foreach ($cats as $k => $_) {
    $sqlCols .= ", {$k}, {$remarkCols[$k]}";
}
$stmt = $conn->prepare(
  "SELECT {$sqlCols} FROM progress WHERE student_id = ? ORDER BY month DESC LIMIT ?"
);
$stmt->bind_param('ii', $studentId, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Ensure at least one record
$thisMonth = $rows[0] ?? array_merge(
  ['month'=>date('Y-m')],
  array_fill_keys(array_keys($cats), 0),
  array_fill_keys(array_values($remarkCols), '')
);

// Helpers
$codeToPct = [0,20,40,60,80,100];
function pctFor(array $rec, string $key, array $map) {
  $c = intval($rec[$key] ?? 0);
  return $map[$c] ?? 0;
}
function emojiFor(int $pct): string {
  if ($pct >= 80) return '😃';
  if ($pct >= 60) return '🙂';
  if ($pct >= 40) return '😐';
  return '😢';
}

// Build datasets
$monthlyPerc = [];
foreach ($cats as $k => $_) {
  $monthlyPerc[$k] = pctFor($thisMonth, $k, $codeToPct);
}
$order = array_slice($rows, 0, 3);
$quarterPerc = [];
foreach ($cats as $k => $_) {
  $sum = array_sum(array_column($order, $k));
  $avg = $order ? round($sum / count($order)) : 0;
  $quarterPerc[$k] = $codeToPct[$avg] ?? 0;
}
$halfPerc = [];
foreach ($cats as $k => $_) {
  $sum = array_sum(array_column($rows, $k));
  $avg = $rows ? round($sum / count($rows)) : 0;
  $halfPerc[$k] = $codeToPct[$avg] ?? 0;
}

// Pull official remark codes
$remarks = [];
foreach ($cats as $k => $_) {
  $remarks[$k] = intval($thisMonth[$k] ?? 0);
}

// Next group
$letter = strtoupper(substr($studentGroup, -1));
$nextGroup = preg_replace('/[A-Z]$/', chr(ord($letter)+1), $studentGroup);
?>

<div class="container my-4">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2><?= htmlspecialchars($studentName) ?>’s Progress</h2>
      <small class="text-muted">
        Group: <?= htmlspecialchars($studentGroup) ?> → Next: <?= htmlspecialchars($nextGroup) ?>
      </small>
    </div>
    <a href="download_progress.php?range=monthly" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download"></i> Download Report Card
    </a>
  </div>

  <!-- Filters -->
  <div class="btn-group mb-4" role="group" id="progressFilters">
    <button type="button" class="btn btn-primary" data-filter="monthly">This Month</button>
    <button type="button" class="btn btn-outline-primary" data-filter="quarter">Last 3 Mo.</button>
    <button type="button" class="btn btn-outline-primary" data-filter="half">Last 6 Mo.</button>
  </div>

  <!-- Overall Performance -->
  <div class="card text-center mb-4 shadow-sm">
    <div class="card-body">
      <h5><i class="bi bi-award-fill"></i> Overall Performance</h5>
      <p id="overallText" class="h3 mb-2"></p>
      <div id="overallEmoji" style="font-size:2rem;"></div>
    </div>
  </div>

  <!-- Metrics Grid -->
  <div class="row">
    <?php foreach ($cats as $key => $label): ?>
      <div class="col-md-4 mb-4">
        <div class="card text-center shadow-sm">
          <div class="card-body">
            <h6 class="mb-3"><?= htmlspecialchars($label) ?></h6>
            <div class="position-relative mx-auto mb-2" style="width:120px; height:120px;">
              <canvas id="chart-<?= $key ?>" width="120" height="120"></canvas>
              <div class="position-absolute top-50 start-50 translate-middle" style="font-size:1.5rem;">
                <span id="emoji-<?= $key ?>"></span>
              </div>
            </div>
            <p class="mb-0 text-muted">
              <?= htmlspecialchars(
                  $gradeLabels[$remarks[$key]] ?? ''
              ) ?>
            </p>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){
  const cats = <?= json_encode(array_keys($cats)) ?>;
  const dataSets = {
    monthly: <?= json_encode(array_values($monthlyPerc)) ?>,
    quarter: <?= json_encode(array_values($quarterPerc)) ?>,
    half:    <?= json_encode(array_values($halfPerc)) ?>
  };
  function colorFor(p) {
    return p >= 50 ? 'rgba(33,150,243,0.7)' : 'rgba(244,67,54,0.7)';
  }
  function emojiFor(p) {
    if (p >= 80) return '😃';
    if (p >= 60) return '🙂';
    if (p >= 40) return '😐';
    return '😢';
  }
  const charts = {};
  cats.forEach((c,i) => {
    const ctx = document.getElementById('chart-'+c).getContext('2d');
    charts[c] = new Chart(ctx, {
      type:'doughnut',
      data:{datasets:[{
        data:[dataSets.monthly[i],100-dataSets.monthly[i]],
        backgroundColor:[colorFor(dataSets.monthly[i]),'#eee'],
        cutout:'75%'
      }]},
      options:{plugins:{legend:{display:false}},responsive:false}
    });
    document.getElementById('emoji-'+c).textContent = emojiFor(dataSets.monthly[i]);
  });
  function computeOverall(arr) {
    const avg = Math.round(arr.reduce((a,b)=>a+b,0)/arr.length);
    if (avg >= 80) return ['Excellent','😃'];
    if (avg >= 60) return ['Good','🙂'];
    if (avg >= 40) return ['Average','😐'];
    return ['Needs Improvement','😢'];
  }
  const ovText   = document.getElementById('overallText');
  const ovEmoji  = document.getElementById('overallEmoji');
  function update(filter) {
    cats.forEach((c,i) => {
      const ch = charts[c];
      ch.data.datasets[0].data = [dataSets[filter][i],100-dataSets[filter][i]];
      ch.data.datasets[0].backgroundColor[0] = colorFor(dataSets[filter][i]);
      ch.update();
      document.getElementById('emoji-'+c).textContent = emojiFor(dataSets[filter][i]);
    });
    const [label,icon] = computeOverall(dataSets[filter]);
    ovText.textContent  = label;
    ovEmoji.textContent = icon;
    document.querySelector('a[href^="download_progress.php"]').href = `download_progress.php?range=${filter}`;
  }
  document.getElementById('progressFilters').addEventListener('click', e => {
    if (!e.target.dataset.filter) return;
    document.querySelectorAll('#progressFilters button').forEach(btn => btn.classList.replace('btn-primary','btn-outline-primary'));
    e.target.classList.replace('btn-outline-primary','btn-primary');
    update(e.target.dataset.filter);
  });
  update('monthly');
})();
</script>
