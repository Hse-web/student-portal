<?php
// File: dashboard/admin/admin_payment.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

$csrf = generate_csrf_token();

// Filters
$searchTerm   = trim($_GET['search']   ?? '');
$centreFilter = (int)($_GET['centre'] ?? 0);
$planFilter   = (int)($_GET['plan']   ?? 0);
$statusFilter = trim($_GET['status']  ?? '');

$centreOptions = $conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$planOptions = $conn->query("SELECT id,plan_name FROM payment_plans ORDER BY plan_name")->fetch_all(MYSQLI_ASSOC);

$sql = "
  SELECT
    pp.id AS proof_id, pp.student_id, pp.file_path, pp.uploaded_at,
    pp.amount AS proof_amount, pp.status AS proof_status,
    s.name AS student_name, s.email AS student_email, s.group_name,
    c.name AS centre_name, p.plan_name
  FROM payment_proofs pp
  JOIN students s ON s.id = pp.student_id
  LEFT JOIN centres c ON c.id = s.centre_id
  LEFT JOIN student_subscriptions ss ON ss.student_id = s.id
    AND ss.id = (SELECT id FROM student_subscriptions WHERE student_id = s.id ORDER BY subscribed_at DESC LIMIT 1)
  LEFT JOIN payment_plans p ON p.id = ss.plan_id
  WHERE 1=1
";

$params = [];
$paramTypes = '';
if ($searchTerm !== '') {
  $sql .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.group_name LIKE ?)";
  $wild = "%{$searchTerm}%";
  $paramTypes .= 'sss';
  $params = [$wild, $wild, $wild];
}
if ($centreFilter > 0) {
  $sql .= " AND s.centre_id = ?";
  $paramTypes .= 'i';
  $params[] = $centreFilter;
}
if ($planFilter > 0) {
  $sql .= " AND p.id = ?";
  $paramTypes .= 'i';
  $params[] = $planFilter;
}
if (in_array($statusFilter, ['Pending', 'Approved', 'Rejected'], true)) {
  $sql .= " AND pp.status = ?";
  $paramTypes .= 's';
  $params[] = $statusFilter;
}
$sql .= " ORDER BY pp.uploaded_at DESC";

$stmt = $conn->prepare($sql);
if ($paramTypes) $stmt->bind_param($paramTypes, ...$params);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Flash
$flash = '';
if (($_GET['msg'] ?? '') === 'approved') {
  $flash = '<div class="bg-green-100 text-green-700 p-3 rounded mb-4">✅ Payment proof approved.</div>';
} elseif (($_GET['msg'] ?? '') === 'rejected') {
  $flash = '<div class="bg-red-100 text-red-700 p-3 rounded mb-4">❌ Payment proof rejected.</div>';
}
?>
<div class="max-w-7xl mx-auto p-6 bg-white rounded shadow">
  <h2 class="text-2xl font-semibold mb-6">💰 Payment Proofs</h2>

  <form method="get" action="?page=admin_payment" class="grid grid-cols-1 md:grid-cols-6 gap-4 mb-6">
    <input type="text" name="search" placeholder="Name, email or group..."
           value="<?= htmlspecialchars($searchTerm) ?>"
           class="border p-2 rounded col-span-2">
    <select name="centre" class="border p-2 rounded">
      <option value="0">All Centres</option>
      <?php foreach($centreOptions as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $centreFilter===$c['id']?'selected':'' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="plan" class="border p-2 rounded">
      <option value="0">All Plans</option>
      <?php foreach($planOptions as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $planFilter===$p['id']?'selected':'' ?>>
          <?= htmlspecialchars($p['plan_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="status" class="border p-2 rounded">
      <option value="">All Status</option>
      <option <?= $statusFilter==='Pending'?'selected':'' ?>>Pending</option>
      <option <?= $statusFilter==='Approved'?'selected':'' ?>>Approved</option>
      <option <?= $statusFilter==='Rejected'?'selected':'' ?>>Rejected</option>
    </select>
    <button class="bg-blue-600 text-white px-4 py-2 rounded">Filter</button>
  </form>

  <?= $flash ?>

  <?php if (empty($payments)): ?>
    <p class="text-gray-500 text-center">No payment proofs found.</p>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm border">
        <thead class="bg-gray-100 text-left">
          <tr>
            <th class="px-3 py-2">ID</th>
            <th class="px-3 py-2">Student</th>
            <th class="px-3 py-2">Email</th>
            <th class="px-3 py-2">Group</th>
            <th class="px-3 py-2">Centre</th>
            <th class="px-3 py-2">Plan</th>
            <th class="px-3 py-2">Status</th>
            <th class="px-3 py-2 text-right">Amount</th>
            <th class="px-3 py-2">Uploaded At</th>
            <th class="px-3 py-2 text-center">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y">
        <?php foreach($payments as $r): ?>
          <tr>
            <td class="px-3 py-2">#<?= (int)$r['proof_id'] ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['student_name']) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['student_email']) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['group_name']) ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['centre_name'] ?? '—') ?></td>
            <td class="px-3 py-2"><?= htmlspecialchars($r['plan_name'] ?? '—') ?></td>
            <td class="px-3 py-2">
              <?php if ($r['proof_status']==='Pending'): ?>
                <span class="bg-yellow-100 text-yellow-800 px-2 py-1 rounded">Pending</span>
              <?php elseif ($r['proof_status']==='Approved'): ?>
                <span class="bg-green-100 text-green-800 px-2 py-1 rounded">Approved</span>
              <?php else: ?>
                <span class="bg-red-100 text-red-800 px-2 py-1 rounded">Rejected</span>
              <?php endif; ?>
            </td>
            <td class="px-3 py-2 text-right">₹<?= number_format($r['proof_amount'], 2) ?></td>
            <td class="px-3 py-2">
              <?php $dt = new DateTime($r['uploaded_at'], new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone('Asia/Kolkata')); echo $dt->format('d M Y H:i'); ?>
              <br>
              <a href="/artovue/uploads/payment_proofs/<?= rawurlencode(basename($r['file_path'])) ?>"
                 target="_blank" class="text-blue-600 underline text-xs">View</a>
            </td>
            <td class="px-3 py-2 text-center">
              <?php if ($r['proof_status']==='Pending'): ?>
                <form method="post" action="../../actions/admin_handle_proof.php" class="inline-block">
                  <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['proof_id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button class="text-green-600 hover:text-green-800"><i class="bi bi-check-lg"></i></button>
                </form>
                <form method="post" action="../../actions/admin_handle_proof.php" class="inline-block">
                  <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="id" value="<?= (int)$r['proof_id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <input type="text" name="rejection_reason" placeholder="Reason"
                         class="border p-1 text-xs rounded" required>
                  <button class="text-red-600 hover:text-red-800"><i class="bi bi-x-lg"></i></button>
                </form>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
