<?php
// File: dashboard/student/progress.php

$page = 'progress';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');


// ─── 1) Identify current student & fetch name + group ────────────────
// fetch name & group
$stmt = $conn->prepare("SELECT s.name, s.group_name FROM students s WHERE s.id=? LIMIT 1");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$stmt->bind_result($studentName, $studentGroup);
$stmt->fetch(); $stmt->close();

// fetch last N months
$stmt = $conn->prepare("
  SELECT month,
         hand_control,    hc_remark,
         coloring_shading, cs_remark,
         observations,    obs_remark,
         temperament,     temp_remark,
         attendance,      att_remark,
         homework,        hw_remark
    FROM progress
   WHERE student_id=?
   ORDER BY month DESC
   LIMIT ?
");
$stmt->bind_param('ii',$studentId,$limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// categories + remark columns
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

// ─── 3) Load THIS month’s raw “code” scores + remarks ────────────────
$thisMonth = date('Y-m');
$sqlCols   = implode(', ', array_merge(array_keys($cats), array_values($remarkCols)));
$stmt = $conn->prepare("
  SELECT $sqlCols
    FROM progress
   WHERE student_id = ?
     AND `month`     = ?
   LIMIT 1
");
$stmt->bind_param('is', $studentId, $thisMonth);
$stmt->execute();
$res = $stmt->get_result();

$progress = array_fill_keys(array_keys($cats), 0);
$remarks  = array_fill_keys(array_keys($cats), '');

if ($row = $res->fetch_assoc()) {
  foreach ($cats as $k => $_) {
    $progress[$k] = (int)$row[$k];
    $remarks[$k]  = trim($row[$remarkCols[$k]]) ?: '';
  }
}
$stmt->close();

// ─── 4) Compute rolling‐average “codes” over last 3 & 6 months ───────
function avgMonths(int $n) {
  global $conn, $studentId, $cats;
  $sum = array_fill_keys(array_keys($cats), 0);
  $cnt = 0;
  $cols = implode(', ', array_keys($cats));
  $stmt = $conn->prepare("
    SELECT $cols
      FROM progress
     WHERE student_id = ?
       AND `month`   <= ?
     ORDER BY `month` DESC
     LIMIT ?
  ");
  $thisMonth = date('Y-m');
  $stmt->bind_param('isi', $studentId, $thisMonth, $n);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) {
    $cnt++;
    foreach ($cats as $k => $_) {
      $sum[$k] += (int)$r[$k];
    }
  }
  $stmt->close();
  if ($cnt) {
    foreach ($sum as $k => $v) {
      $sum[$k] = (int)round($v / $cnt);
    }
  }
  return $sum;
}
$quarterCodes = avgMonths(3);
$halfCodes    = avgMonths(6);

// ─── 5) Map your 1–4 codes into 0–100% slices ────────────────────────
//    e.g. 1 → 25%, 2 → 50%, 3 → 75%, 4 → 100%
$codeToPct = [
  0 => 0,
  1 => 25,
  2 => 50,
  3 => 75,
  4 => 100,
];

$monthlyPerc = array_map(fn($c) => $codeToPct[$c] ?? 0, $progress);
$quarterPerc = array_map(fn($c) => $codeToPct[$c] ?? 0, $quarterCodes);
$halfPerc    = array_map(fn($c) => $codeToPct[$c] ?? 0, $halfCodes);

// ─── 6) Compute your “Next Group” string ─────────────────────────────
$letter    = strtoupper(substr($studentGroup, -1));
$nextGroup = preg_replace('/[A-Z]$/', chr(ord($letter)+1), $studentGroup);

?>
  <div class="container">

    <!-- Overall Performance -->
    <div class="card mb-4 text-center">
      <div class="card-body">
        <h5 class="card-title">
          <i class="bi bi-award-fill"></i> Overall Performance
        </h5>
        <p id="overallText" class="display-6 mb-0"></p>
        <img id="overallEmoji" style="width:32px;margin-top:.5rem">
      </div>
    </div>

    <!-- Header & Group Info -->
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h4><i class="bi bi-bar-chart-line"></i> Monthly Breakdown</h4>
      <div>
        <strong>Group:</strong> <?=htmlspecialchars($studentGroup)?> 
        &nbsp;|&nbsp;
        <strong>Next:</strong> <?=htmlspecialchars($nextGroup)?>
      </div>
    </div>

    <!-- Filter Buttons -->
    <div class="btn-group btn-group-toggle mb-4" data-bs-toggle="buttons">
      <label class="btn btn-outline-primary active" data-filter="monthly">
        <input type="radio" name="filter" autocomplete="off" checked> This Month
      </label>
      <label class="btn btn-outline-primary" data-filter="quarter">
        <input type="radio" name="filter" autocomplete="off"> Last 3 Mo.
      </label>
      <label class="btn btn-outline-primary" data-filter="half">
        <input type="radio" name="filter" autocomplete="off"> Last 6 Mo.
      </label>
    </div>

    <!-- Download PDF -->
    <div class="text-end mb-4">
      <a href="download_progress.php?range=monthly" 
         class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-download"></i> Download PDF
      </a>
    </div>

    <!-- Individual Metrics -->
    <div class="row g-4">
      <?php foreach ($cats as $key => $label): ?>
        <div class="col-sm-6 col-lg-4">
          <div class="card card-progress p-3 text-center">
            <h6><?=htmlspecialchars($label)?></h6>
            <div class="position-relative mx-auto my-3" 
                 style="width:120px;height:120px;">
              <canvas id="ring-<?=$key?>" width="120" height="120"></canvas>
              <img id="emoji-<?=$key?>" class="ring-emoji" src="">
            </div>
            <p class="small text-muted">
              <?= $remarks[$key]
                 ? '<i class="bi bi-chat-dots"></i> ' 
                   . htmlspecialchars($remarks[$key])
                 : '<i class="bi bi-chat-dots"></i> No remark' 
              ?>
            </p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Chart.js -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
  (()=>{
    const cats    = <?=json_encode(array_keys($cats))?>;
    const monthly = <?=json_encode(array_values($monthlyPerc))?>;
    const quarter = <?=json_encode(array_values($quarterPerc))?>;
    const half    = <?=json_encode(array_values($halfPerc))?>;

    function makeGrad(ctx,p){
      let c0,c1;
      if      (p>=76){c0='#4caf50';c1='#66bb6a';}  // Excellent
      else if (p>=51){c0='#8bc34a';c1='#9ccc65';}  // Good
      else if (p>=26){c0='#ff9800';c1='#ffb74d';}  // Average
      else           {c0='#f44336';c1='#e57373';}  // Needs Improvement
      const g = ctx.createLinearGradient(0,0,120,0);
      g.addColorStop(0,c0);
      g.addColorStop(1,c1);
      return g;
    }
    function emojiFor(p){
      if      (p>=76) return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f600.png';
      else if (p>=51) return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f642.png';
      else if (p>=26) return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f641.png';
      else             return 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/72x72/1f622.png';
    }
    function computeOverall(arr){
      const sum = arr.reduce((a,b)=>a+b,0);
      const avgPct = Math.round(sum/arr.length);
      let label;
      if      (avgPct >= 76) label='Excellent';
      else if (avgPct >= 51) label='Good';
      else if (avgPct >= 26) label='Average';
      else                    label='Needs Improvement';
      return {pct:avgPct, label};
    }

    // initialize rings & emojis
    const charts = {};
    cats.forEach((cat,i)=>{
      const ctx = document.getElementById(`ring-${cat}`).getContext('2d');
      charts[cat] = new Chart(ctx, {
        type:'doughnut',
        data:{datasets:[{
          data:[ monthly[i], 100-monthly[i] ],
          backgroundColor:[ makeGrad(ctx,monthly[i]), '#eee' ],
          borderWidth:0
        }]},
        options:{
          cutout:'75%', animation:{duration:800}, responsive:false,
          plugins:{legend:{display:false},tooltip:{enabled:false}}
        }
      });
      document.getElementById(`emoji-${cat}`).src = emojiFor(monthly[i]);
    });

    // set overall
    const o0 = computeOverall(monthly);
    const ovEl = document.getElementById('overallText');
    ovEl.textContent = o0.label;
    ovEl.style.color = makeGrad(
      document.createElement('canvas').getContext('2d'),
      o0.pct
    );
    document.getElementById('overallEmoji').src = emojiFor(o0.pct);

    // filter buttons
    document.querySelectorAll('[data-filter]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        document.querySelectorAll('[data-filter]')
          .forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        const key = btn.dataset.filter;
        const set = key==='monthly'? monthly
                  : key==='quarter'? quarter
                  : half;
        cats.forEach((cat,i)=>{
          const ch = charts[cat];
          ch.data.datasets[0].data = [ set[i], 100-set[i] ];
          ch.data.datasets[0].backgroundColor[0] = makeGrad(ch.ctx, set[i]);
          ch.update();
          document.getElementById(`emoji-${cat}`).src = emojiFor(set[i]);
        });
        // overall update
        const o = computeOverall(set);
        ovEl.textContent = o.label;
        ovEl.style.color = makeGrad(
          document.createElement('canvas').getContext('2d'),
          o.pct
        );
        document.getElementById('overallEmoji').src = emojiFor(o.pct);
        // adjust PDF link
        document.querySelector('.text-end a')
          .href = `download_progress.php?range=${key}`;
      });
    });
  })();
  </script>
<?php


