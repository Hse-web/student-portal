<?php
// File: dashboard/admin/student_subscriptions.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── Filters ─────────────────────────────────────────
$groupFilter = $_GET['group'] ?? '';
$centreFilter = $_GET['centre'] ?? '';
$periodFilter = $_GET['period'] ?? '';

$where = 'WHERE 1=1';
$params = [];
$types = '';
if ($groupFilter !== '') {
  $where .= ' AND s.group_name = ?';
  $params[] = $groupFilter;
  $types .= 's';
}
if ($centreFilter !== '') {
  $where .= ' AND c.id = ?';
  $params[] = $centreFilter;
  $types .= 'i';
}
if ($periodFilter === 'monthly') {
  $where .= ' AND ss.subscribed_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)';
}
if ($periodFilter === 'quarterly') {
  $where .= ' AND ss.subscribed_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
}
if ($periodFilter === '6months') {
  $where .= ' AND ss.subscribed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)';
}

// ─── Fetch Filters ───────────────────────────────────
$groupsList = $conn->query("SELECT DISTINCT group_name FROM students ORDER BY group_name")->fetch_all(MYSQLI_NUM);
$centresList = $conn->query("SELECT id, name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// ─── Fetch Subscriptions ─────────────────────────────
$sql = "
  SELECT ss.id, ss.student_id, s.name AS student_name, ss.plan_id,
         p.plan_name, p.amount, p.duration_months, p.gst_percent,
         ss.subscribed_at, ss.referral_applied, ss.referral_amount,
         c.name AS centre_name, s.group_name
    FROM student_subscriptions ss
    JOIN students s ON s.id = ss.student_id
    JOIN payment_plans p ON p.id = ss.plan_id
    JOIN centres c ON c.id = p.centre_id
    $where
  ORDER BY ss.subscribed_at DESC
";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$subscriptions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Summaries ───────────────────────────────────────
$totalSubscriptions = count($subscriptions);
$totalAmount = $totalGST = $totalReferral = 0;
foreach ($subscriptions as $sub) {
  $amount = (float)$sub['amount'];
  $gst = $amount * ((float)$sub['gst_percent']) / 100;
  $totalAmount += $amount;
  $totalGST += $gst;
  $totalReferral += (float)$sub['referral_amount'];
}

// ─── Chart Data ──────────────────────────────────────
$monthlyChartData = [];
$chartStmt = $conn->query("SELECT DATE_FORMAT(subscribed_at, '%b %Y') AS month, COUNT(*) AS count FROM student_subscriptions GROUP BY month ORDER BY subscribed_at DESC LIMIT 12");
while ($row = $chartStmt->fetch_assoc()) {
  $monthlyChartData[] = $row;
}
$monthlyChartData = array_reverse($monthlyChartData);
?>
<div class="max-w-7xl mx-auto p-6 bg-white rounded shadow">
  <h2 class="text-2xl font-semibold mb-6">📋 Student Subscriptions</h2>

  <form method="get" action="student_subscriptions.php" class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
    <select name="centre" class="border p-2 rounded">
      <option value="">All Centres</option>
      <?php foreach ($centresList as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $centreFilter == $c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="group" class="border p-2 rounded">
      <option value="">All Groups</option>
      <?php foreach ($groupsList as [$g]): ?>
        <option value="<?= htmlspecialchars($g) ?>" <?= $groupFilter == $g ? 'selected' : '' ?>>
          <?= htmlspecialchars($g) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="period" class="border p-2 rounded">
      <option value="">All Time</option>
      <option value="monthly" <?= $periodFilter === 'monthly' ? 'selected' : '' ?>>Monthly</option>
      <option value="quarterly" <?= $periodFilter === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
      <option value="6months" <?= $periodFilter === '6months' ? 'selected' : '' ?>>Last 6 Months</option>
    </select>
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Filter</button>
    <a href="student_subscriptions.php" class="bg-gray-200 text-gray-800 px-4 py-2 rounded">Reset</a>
  </form>

  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-blue-50 border border-blue-200 rounded p-4">
      <div class="text-sm text-blue-700">Total Subscriptions</div>
      <div class="text-xl font-bold"><?= $totalSubscriptions ?></div>
    </div>
    <div class="bg-green-50 border border-green-200 rounded p-4">
      <div class="text-sm text-green-700">Total Amount (excl. GST)</div>
      <div class="text-xl font-bold">₹<?= number_format($totalAmount, 2) ?></div>
    </div>
    <div class="bg-yellow-50 border border-yellow-200 rounded p-4">
      <div class="text-sm text-yellow-700">GST + Referral Discounts</div>
      <div class="text-sm">GST: ₹<?= number_format($totalGST, 2) ?></div>
      <div class="text-sm">Referral: ₹<?= number_format($totalReferral, 2) ?></div>
    </div>
  </div>

  <canvas id="subsChart" height="120"></canvas>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script>
    const ctx = document.getElementById('subsChart').getContext('2d');
    new Chart(ctx, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($monthlyChartData, 'month')) ?>,
        datasets: [{
          label: 'Subscriptions per Month',
          data: <?= json_encode(array_map('intval', array_column($monthlyChartData, 'count'))) ?>,
          backgroundColor: 'rgba(59,130,246,0.5)',
          borderColor: 'rgba(59,130,246,1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        scales: {
          y: {
            beginAtZero: true
          }
        }
      }
    });
  </script>

  <?php if (!$subscriptions): ?>
    <div class="text-gray-500 mt-6">No subscriptions found.</div>
  <?php else: ?>
  <div class="overflow-x-auto mt-6">
    <table class="min-w-full border text-sm text-left">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-3 py-2">Student</th>
          <th class="px-3 py-2">Group</th>
          <th class="px-3 py-2">Centre</th>
          <th class="px-3 py-2">Plan</th>
          <th class="px-3 py-2">Duration</th>
          <th class="px-3 py-2">Amount</th>
          <th class="px-3 py-2">GST%</th>
          <th class="px-3 py-2">Referral</th>
          <th class="px-3 py-2">Subscribed On</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($subscriptions as $sub): ?>
        <tr>
          <td class="px-3 py-2 font-medium text-gray-900">
            <?= htmlspecialchars($sub['student_name']) ?>
          </td>
          <td class="px-3 py-2 text-gray-700">
            <?= htmlspecialchars($sub['group_name']) ?>
          </td>
          <td class="px-3 py-2">
            <?= htmlspecialchars($sub['centre_name']) ?>
          </td>
          <td class="px-3 py-2">
            <?= htmlspecialchars($sub['plan_name']) ?>
          </td>
          <td class="px-3 py-2">
            <?= (int)$sub['duration_months'] ?> month(s)
          </td>
          <td class="px-3 py-2">
            ₹<?= number_format($sub['amount'], 2) ?>
          </td>
          <td class="px-3 py-2">
            <?= number_format($sub['gst_percent'], 2) ?>%
          </td>
          <td class="px-3 py-2">
            <?= $sub['referral_applied'] ? 'Yes (₹' . number_format($sub['referral_amount'], 2) . ')' : '—' ?>
          </td>
          <td class="px-3 py-2 text-gray-600">
            <?= date('d M Y', strtotime($sub['subscribed_at'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
