<?php
// File: dashboard/student/progress.php

$page = 'progress';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../helpers/functions.php';

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

// 3) Define categories & remarks
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

// 4) Fetch up to last 6 months
$limit = 6;
$sqlCols = 'month';
foreach ($cats as $k => $_) {
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

// how many months of data do we actually have?
$numRows      = count($rows);
$canDoQuarter = $numRows >= 3;
$canDoHalf    = $numRows >= 6;

// Guarantee at least “this month” placeholder
$thisMonth = $rows[0] ?? array_merge(
  ['month' => date('Y-m')],
  array_fill_keys(array_keys($cats), 0),
  array_fill_keys(array_values($remarkCols), '')
);

// Helpers to map to percentages
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

// Build datasets
$monthlyPerc = [];
foreach ($cats as $k=>$_) {
  $monthlyPerc[$k] = pctFor($thisMonth,$k,$codeToPct);
}
$quarterPerc = [];  // average of last 3
foreach ($cats as $k=>$_) {
  $slice = array_slice($rows,0,3);
  $avg   = $slice ? round(array_sum(array_column($slice,$k)) / count($slice)) : 0;
  $quarterPerc[$k] = $codeToPct[$avg] ?? 0;
}
$halfPerc = [];     // average of last 6
foreach ($cats as $k=>$_) {
  $avg   = $rows ? round(array_sum(array_column($rows,$k)) / count($rows)) : 0;
  $halfPerc[$k] = $codeToPct[$avg] ?? 0;
}

// Official remark codes
$remarks = [];
foreach ($cats as $k=>$_) {
  $remarks[$k] = intval($thisMonth[$k] ?? 0);
}
?>
<div class="container my-4">
  <!-- Header -->
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2><?= htmlspecialchars($studentName) ?>’s Progress</h2>
      <small class="text-muted">
        Group: <?= htmlspecialchars($studentGroup) ?>
        <?php if ($nextGroup): ?>→ Next: <?= htmlspecialchars($nextGroup) ?><?php endif; ?>
      </small>
    </div>
    <a href="download_progress.php?range=monthly" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-download"></i> Download Report Card
    </a>
  </div>

  <!-- Filters -->
  <div class="btn-group mb-4" role="group" id="progressFilters">
    <button type="button" class="btn btn-primary" data-filter="monthly">
      This Month
    </button>
    <button
      type="button"
      class="btn btn-outline-primary"
      data-filter="quarter"
      <?= $canDoQuarter ? '' : 'disabled title="Not enough data for 3-month report"' ?>
    >
      Last 3 Mo.
    </button>
    <button
      type="button"
      class="btn btn-outline-primary"
      data-filter="half"
      <?= $canDoHalf ? '' : 'disabled title="Not enough data for 6-month report"' ?>
    >
      Last 6 Mo.
    </button>
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
            <div class="position-relative mx-auto mb-2" style="width:120px;height:120px">
              <canvas id="chart-<?= $key ?>" width="120" height="120"></canvas>
              <div class="position-absolute top-50 start-50 translate-middle" style="font-size:1.5rem;">
                <span id="emoji-<?= $key ?>"></span>
              </div>
            </div>
            <p class="mb-0 text-muted">
              <?= htmlspecialchars($gradeLabels[$remarks[$key]] ?? '') ?>
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

  const ovText  = document.getElementById('overallText');
  const ovEmoji = document.getElementById('overallEmoji');

  function update(filter) {
    // if somehow someone clicks disabled button, abort
    if (document.querySelector(`#progressFilters button[data-filter="${filter}"]`).disabled) {
      alert('No progress available for this period.');
      return;
    }

    cats.forEach((c,i) => {
      const val = dataSets[filter][i];
      const ch  = charts[c];
      ch.data.datasets[0].data             = [val,100-val];
      ch.data.datasets[0].backgroundColor[0] = colorFor(val);
      ch.update();
      document.getElementById('emoji-'+c).textContent = emojiFor(val);
    });

    const [label,icon] = computeOverall(dataSets[filter]);
    ovText.textContent  = label;
    ovEmoji.textContent = icon;

    document.querySelector('a[href^="download_progress.php"]')
      .href = `download_progress.php?range=${filter}`;
  }

  document.getElementById('progressFilters')
    .addEventListener('click', e => {
      const f = e.target.dataset.filter;
      if (!f) return;
      document.querySelectorAll('#progressFilters button')
        .forEach(btn => btn.classList.replace('btn-primary','btn-outline-primary'));
      e.target.classList.replace('btn-outline-primary','btn-primary');
      update(f);
    });

  update('monthly');
})();
</script>
