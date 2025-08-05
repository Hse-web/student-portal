<?php
// File: dashboard/student/report_card.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';

// 1) Auth guard
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../../login/index.php');
    exit;
}

// 2) Identify student & fetch name/group
$studentId   = (int)$_SESSION['student_id'];
$stmt = $conn->prepare("
  SELECT s.name, s.group_name
    FROM students s
   WHERE s.id = ?
   LIMIT 1
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName, $studentGroup);
$stmt->fetch();
$stmt->close();

// 3) Choose Monthly / 3-Month / 6-Month
$range  = $_GET['range'] ?? 'monthly';
$limits = ['monthly'=>1,'quarter'=>3,'half'=>6];
$limit  = $limits[$range] ?? 1;

// 4) Fetch the last N months of progress
$stmt = $conn->prepare("
  SELECT month,
         hand_control,    hc_remark,
         coloring_shading, cs_remark,
         observations,    obs_remark,
         temperament,     temp_remark,
         attendance,      att_remark,
         homework,        hw_remark
    FROM progress
   WHERE student_id = ?
   ORDER BY month DESC
   LIMIT ?
");
$stmt->bind_param('ii', $studentId, $limit);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5) Compute per‐category average (0–10) and pick the “latest” row
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

$sum = array_fill_keys(array_keys($cats), 0);
foreach ($rows as $r) {
  foreach ($cats as $k => $_) {
    $sum[$k] += (int)$r[$k];
  }
}
$count  = max(1, count($rows));
$avg    = array_map(fn($v) => round($v / $count, 2), $sum);
$latest = $rows[0] ?? [];

// 6) Grade‐code helper
function gradeCode(int $s): string {
  return match (true) {
    $s >= 8 => 'S',  // Satisfactory
    $s >= 6 => 'I',  // Improving
    $s >= 4 => 'N',  // Needs Improvement
    default => 'U',  // Unsatisfactory
  };
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>📋 Report Card</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background:#f0f2f5; }
    .card-report { border:none; border-radius:.75rem; box-shadow:0 3px 8px rgba(0,0,0,0.1); }
    .grade-badge { font-size:1.1rem; width:2.5rem; }
  </style>
</head>
<body class="pt-4">
  <div class="container">

    <!-- Header + Download -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h3>📋 Report Card</h3>
      <a href="download_progress.php?range=<?= urlencode($range) ?>"
         class="btn btn-outline-primary">
        ⬇️ Download PDF
      </a>
    </div>

    <!-- Student Info + Range Picker -->
    <div class="bg-white p-3 rounded mb-4 shadow-sm">
      <div class="row">
        <div class="col"><strong>Student:</strong> <?= htmlspecialchars($studentName) ?></div>
        <div class="col"><strong>Group:</strong> <?= htmlspecialchars($studentGroup) ?></div>
        <div class="col text-end">
          <strong>Range:</strong>
          <select onchange="location.search='?range='+this.value"
                  class="form-select d-inline w-auto">
            <option value="monthly" <?= $range==='monthly' ? 'selected':'' ?>>Monthly</option>
            <option value="quarter" <?= $range==='quarter' ? 'selected':'' ?>>3-Month</option>
            <option value="half"    <?= $range==='half'    ? 'selected':'' ?>>6-Month</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Grades Grid -->
    <div class="row gy-4">
      <?php foreach ($cats as $key => $label):
        $score10 = $avg[$key];                    // 0–10
        $code    = gradeCode((int)$avg[$key]);    // S/I/N/U
        $remark  = $latest[ $remarkCols[$key] ] ?? '';
      ?>
      <div class="col-md-6 col-lg-4">
        <div class="card card-report p-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0"><?= $label ?></h5>
            <span class="badge bg-secondary grade-badge"><?= $code ?></span>
          </div>
          <p class="small text-muted mb-2"><?= round($score10*10) ?>%</p>
          <?php if ($remark): ?>
            <p class="fst-italic">“<?= htmlspecialchars($remark) ?>”</p>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach ?>
    </div>

    <!-- Legend -->
    <div class="bg-white rounded mt-4 p-3 shadow-sm">
      <h5>Grade Key</h5>
      <ul>
        <li><strong>S</strong> — Satisfactory (8–10)</li>
        <li><strong>I</strong> — Improving (6–7)</li>
        <li><strong>N</strong> — Needs Improvement (4–5)</li>
        <li><strong>U</strong> — Unsatisfactory (0–3)</li>
      </ul>
    </div>

  </div>
</body>
</html>
