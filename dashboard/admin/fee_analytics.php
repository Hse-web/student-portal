<?php
// File: dashboard/admin/fee_analytics.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1. Filters
$selectedCentre = (int)($_GET['centre'] ?? 0);
$year           = (int)($_GET['year']   ?? date('Y'));
$view           = $_GET['view']         ?? 'monthly';
$export         = $_GET['export']       ?? '';

// 2. Centres dropdown
$centres = $conn
  ->query("SELECT id, name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

// 3. Build period SQL
switch ($view) {
  case 'quarterly':
    $labelSql = "CONCAT(YEAR(uploaded_at), '-Q', QUARTER(uploaded_at)) AS period";
    break;
  case 'halfyear':
    $labelSql = "CONCAT(YEAR(uploaded_at), '-H', IF(MONTH(uploaded_at)<=6,1,2)) AS period";
    break;
  default:
    $labelSql = "DATE_FORMAT(uploaded_at, '%Y-%m') AS period";
    $view     = 'monthly';
}

// 4. Monthly + growth data
$monthlyQuery = $conn->prepare("
  SELECT
    $labelSql,
    SUM(amount) AS total
  FROM payment_proofs
  WHERE status='Approved'
    AND YEAR(uploaded_at)=?
    AND (?=0 OR student_id IN (SELECT id FROM students WHERE centre_id=?))
  GROUP BY period
  ORDER BY period ASC
");
$monthlyQuery->bind_param('iii', $year, $selectedCentre, $selectedCentre);
$monthlyQuery->execute();
$monthlyResult  = $monthlyQuery->get_result();
$monthlyLabels  = $monthlyData = $growthData = [];
$prev = null;
while ($r = $monthlyResult->fetch_assoc()) {
  $monthlyLabels[] = $r['period'];
  $monthlyData[]   = (float)$r['total'];
  if ($prev === null) {
    $growthData[] = 0;
  } else {
    $growthData[] = round((($r['total'] - $prev) / $prev) * 100, 2);
  }
  $prev = $r['total'];
}
$monthlyQuery->close();

// 5. Centre-wise
$centreData = $centreLabels = [];
$res = $conn->query("
  SELECT c.name, SUM(pp.amount) AS total
    FROM payment_proofs pp
    JOIN students s ON s.id=pp.student_id
    JOIN centres c  ON s.centre_id=c.id
   WHERE pp.status='Approved' AND YEAR(pp.uploaded_at)=$year
   GROUP BY c.id
   ORDER BY total DESC
");
while ($r = $res->fetch_assoc()) {
  $centreLabels[] = $r['name'];
  $centreData[]   = (float)$r['total'];
}

// 6. Plan-wise
$planLabels = $planData = [];
$res = $conn->query("
  SELECT p.plan_name, SUM(pp.amount) AS total
    FROM payment_proofs pp
    JOIN student_subscriptions ss ON ss.student_id=pp.student_id
    JOIN payment_plans p ON p.id=ss.plan_id
   WHERE pp.status='Approved' AND YEAR(pp.uploaded_at)=$year
   GROUP BY p.id
   ORDER BY total DESC
");
while ($r = $res->fetch_assoc()) {
  $planLabels[] = $r['plan_name'];
  $planData[]   = (float)$r['total'];
}

// 7. Detailed for export/table
$params = [$year];
$types  = 'i';
$sql = "
  SELECT
    s.name, s.email, s.group_name, c.name AS centre_name,
    p.plan_name, ss.subscribed_at, pp.amount, pp.uploaded_at
  FROM payment_proofs pp
  JOIN students s ON s.id=pp.student_id
  JOIN centres c  ON s.centre_id=c.id
  JOIN student_subscriptions ss ON ss.student_id=s.id
  JOIN payment_plans p ON p.id=ss.plan_id
  WHERE pp.status='Approved' AND YEAR(pp.uploaded_at)=?
";
if ($selectedCentre) {
  $sql .= " AND s.centre_id=?";
  $types     .= 'i';
  $params[]  = $selectedCentre;
}
$exportStmt = $conn->prepare($sql);
$exportStmt->bind_param($types, ...$params);
$exportStmt->execute();
$exportData = $exportStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$exportStmt->close();

// Handle CSV/PDF export
if ($export==='csv') {
  header('Content-Type:text/csv');
  header('Content-Disposition:attachment;filename="fee_analytics.csv"');
  $out = fopen('php://output','w');
  fputcsv($out,['Student','Email','Group','Centre','Plan','Start','Amount','Paid On']);
  foreach ($exportData as $r) {
    fputcsv($out,[
      $r['name'],$r['email'],$r['group_name'],$r['centre_name'],
      $r['plan_name'],$r['subscribed_at'],$r['amount'],$r['uploaded_at']
    ]);
  }
  fclose($out);
  exit;
}
if ($export==='pdf') {
  require_once __DIR__ . '/../../libs/fpdf/fpdf.php';
  $pdf = new FPDF('L','mm','A4');
  $pdf->AddPage();
  $pdf->SetFont('Arial','B',12);
  $pdf->Cell(0,10,"Fee Analytics Report - $year",0,1,'C');
  $pdf->Ln(4);
  $pdf->SetFont('Arial','B',10);
  $hdrs  = ['Student','Email','Group','Centre','Plan','Start','Amount','Paid On'];
  $w     = [35,55,25,30,40,25,20,25];
  foreach($hdrs as $i=>$h) $pdf->Cell($w[$i],7,$h,1);
  $pdf->Ln();
  $pdf->SetFont('Arial','',9);
  foreach($exportData as $r){
    $pdf->Cell($w[0],7,$r['name'],1);
    $pdf->Cell($w[1],7,$r['email'],1);
    $pdf->Cell($w[2],7,$r['group_name'],1);
    $pdf->Cell($w[3],7,$r['centre_name'],1);
    $pdf->Cell($w[4],7,$r['plan_name'],1);
    $pdf->Cell($w[5],7,date('Y-m-d',strtotime($r['subscribed_at'])),1);
    $pdf->Cell($w[6],7,'₹'.number_format($r['amount'],2),1);
    $pdf->Cell($w[7],7,date('Y-m-d',strtotime($r['uploaded_at'])),1);
    $pdf->Ln();
  }
  $pdf->Output('D',"fee_analytics_$year.pdf");
  exit;
}
?>
<!-- Main container -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

  <!-- Filters -->
  <form method="get" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
    <input type="hidden" name="page" value="fee_analytics">
    <select name="year" class="border rounded p-2 w-full">
      <?php for($y=date('Y');$y>=2022;$y--): ?>
        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
      <?php endfor; ?>
    </select>
    <select name="centre" class="border rounded p-2 w-full">
      <option value="0">All Centres</option>
      <?php foreach($centres as $c): ?>
        <option value="<?=$c['id']?>" <?=$c['id']===$selectedCentre?'selected':''?>>
          <?=htmlspecialchars($c['name'])?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="view" class="border rounded p-2 w-full">
      <option value="monthly"    <?=$view==='monthly'   ?'selected':''?>>Monthly</option>
      <option value="quarterly"  <?=$view==='quarterly' ?'selected':''?>>Quarterly</option>
      <option value="halfyear"   <?=$view==='halfyear'  ?'selected':''?>>Half-Yearly</option>
    </select>
    <div class="flex space-x-2">
      <button type="submit" class="flex-1 bg-purple-600 text-white rounded p-2">Apply</button>
      <a href="?page=fee_analytics&year=<?=$year?>&centre=<?=$selectedCentre?>&view=<?=$view?>&export=csv"
         class="bg-green-600 text-white rounded p-2">CSV</a>
      <a href="?page=fee_analytics&year=<?=$year?>&centre=<?=$selectedCentre?>&view=<?=$view?>&export=pdf"
         class="bg-blue-600 text-white rounded p-2">PDF</a>
    </div>
  </form>

  <!-- Detailed Table -->
  <div class="overflow-x-auto bg-white rounded shadow">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <?php foreach(['Student','Email','Group','Centre','Plan','Start','Amount','Paid On'] as $h): ?>
            <th class="px-3 py-2 text-left text-sm font-medium text-gray-700"><?=$h?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php foreach($exportData as $r): ?>
        <tr class="hover:bg-gray-50">
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['name'])?></td>
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['email'])?></td>
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['group_name'])?></td>
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['centre_name'])?></td>
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['plan_name'])?></td>
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['subscribed_at'])?></td>
          <td class="px-3 py-2 text-sm text-gray-800">₹<?=number_format($r['amount'],2)?></td>
          <td class="px-3 py-2 text-sm text-gray-800"><?=htmlspecialchars($r['uploaded_at'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Charts -->
  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="bg-white rounded shadow p-4">
      <h3 class="text-lg font-semibold mb-2">📅 <?=ucfirst($view)?> Collection</h3>
      <canvas id="monthlyChart" class="w-full h-64"></canvas>
    </div>
    <div class="bg-white rounded shadow p-4">
      <h3 class="text-lg font-semibold mb-2">🏢 Centre-wise Revenue</h3>
      <canvas id="centreChart" class="w-full h-64"></canvas>
    </div>
    <div class="bg-white rounded shadow p-4 md:col-span-2">
      <h3 class="text-lg font-semibold mb-2">📦 Plan-wise Revenue</h3>
      <canvas id="planChart" class="w-full h-64"></canvas>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
new Chart(monthlyCtx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($monthlyLabels) ?>,
    datasets: [{
      label: '₹ Collected',
      data: <?= json_encode($monthlyData) ?>,
      backgroundColor: '#9C27B0'
    },{
      label: '% Growth vs Prev',
      data: <?= json_encode($growthData) ?>,
      type: 'line',
      yAxisID: 'y1'
    }]
  },
  options: {
    scales: {
      y: { beginAtZero:true, title:{display:true,text:'Amount (₹)'} },
      y1:{ beginAtZero:true, position:'right', grid:{drawOnChartArea:false}, title:{display:true,text:'Growth %'} }
    }
  }
});
const centreCtx = document.getElementById('centreChart').getContext('2d');
new Chart(centreCtx, {
  type:'pie',
  data:{ labels:<?=json_encode($centreLabels)?>, datasets:[{ data:<?=json_encode($centreData)?> }] }
});
const planCtx = document.getElementById('planChart').getContext('2d');
new Chart(planCtx,{ type:'bar', data:{ labels:<?=json_encode($planLabels)?>, datasets:[{ data:<?=json_encode($planData)?> }] } });
</script>
