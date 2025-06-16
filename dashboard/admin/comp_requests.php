<?php
// ───────────────────────────────────────────────────────────────────────────────
// File: dashboard/admin/comp_requests.php
// Included via index.php?page=comp_requests
// ───────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// — 1) Read & sanitize filter inputs —
$start   = $_GET['start']   ?? '';
$end     = $_GET['end']     ?? '';
$status  = $_GET['status']  ?? '';$student = $_GET['student'] ?? '';

// Validate date formats
if ($start && !preg_match('/^d{4}-d{2}-d{2}$/', $start)) $start = '';
if ($end   && !preg_match('/^d{4}-d{2}-d{2}$/', $end))   $end   = '';
// Allow only these statuses
if (!in_array($status, ['approved','missed'], true)) $status = '';

// — 2) Build dynamic WHERE clauses —
$where   = [];
$params  = [];
$types   = '';

if ($start) {
    $where[]  = 'c.requested_at >= ?';
    $types   .= 's';
    $params[] = $start . ' 00:00:00';
}
if ($end) {
    $where[]  = 'c.requested_at <= ?';
    $types   .= 's';
    $params[] = $end . ' 23:59:59';
}
if ($status) {
    $where[]  = 'c.status = ?';
    $types   .= 's';
    $params[] = $status;
}
if ($student) {
    $where[]  = 's.name LIKE ?';
    $types   .= 's';
    $params[] = "%{$student}%";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// — 3) Fetch summary counts —
$summary = ['total'=>0,'approved'=>0,'missed'=>0];

$sql = "
    SELECT c.status, COUNT(*) AS cnt
      FROM compensation_requests c
      JOIN students s ON s.user_id = c.user_id
      $whereSql
      GROUP BY c.status
";
$stmt = $conn->prepare($sql);
if ($where) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $summary['total']             += $row['cnt'];
    $summary[$row['status']]      = $row['cnt'];
}
$stmt->close();

// — 4) Fetch detailed rows —
$sql = "
    SELECT
      c.id,
      DATE_FORMAT(c.absent_date,'%Y-%m-%d') AS absent_date,
      DATE_FORMAT(c.comp_date,'%Y-%m-%d')   AS comp_date,
      c.slot,
      s.name AS student_name,
      DATE_FORMAT(c.requested_at,'%Y-%m-%d %h:%i %p') AS requested_at,
      c.status
    FROM compensation_requests c
    JOIN students s ON s.user_id = c.user_id
    $whereSql
    ORDER BY c.requested_at DESC
    LIMIT 1000
";
$stmt = $conn->prepare($sql);
if ($where) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// — 5) Flash messages —
$flashS = $_SESSION['flash_success'] ?? '';
$flashE = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>

<div class="max-w-7xl mx-auto p-6 space-y-6">
  <h2 class="text-2xl font-bold">Compensation Requests</h2>

  <?php if($flashS): ?>
    <div class="p-4 bg-green-100 border border-green-400 text-green-800 rounded">
      <?= htmlspecialchars($flashS) ?>
    </div>
  <?php elseif($flashE): ?>
    <div class="p-4 bg-red-100 border border-red-400 text-red-800 rounded">
      <?= htmlspecialchars($flashE) ?>
    </div>
  <?php endif; ?>

  <!-- Summary cards -->
  <div class="grid grid-cols-3 gap-4">
    <div class="p-4 bg-white shadow rounded text-center">
      <div class="text-sm font-medium text-gray-500">Total Requests</div>
      <div class="mt-2 text-2xl font-bold"><?= $summary['total'] ?></div>
    </div>
    <div class="p-4 bg-white shadow rounded text-center">
      <div class="text-sm font-medium text-green-600">Approved</div>
      <div class="mt-2 text-2xl font-bold"><?= $summary['approved'] ?></div>
    </div>
    <div class="p-4 bg-white shadow rounded text-center">
      <div class="text-sm font-medium text-red-600">Missed</div>
      <div class="mt-2 text-2xl font-bold"><?= $summary['missed'] ?></div>
    </div>
  </div>

  <!-- Filter form -->
  <form method="GET" class="grid grid-cols-4 gap-4 items-end">
    <input type="hidden" name="page" value="comp_requests">

    <div>
      <label class="block text-sm font-medium text-gray-700">From</label>
      <input type="date" name="start" value="<?= htmlspecialchars($start) ?>"
        class="mt-1 block w-full border border-gray-300 rounded p-2">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">To</label>
      <input type="date" name="end" value="<?= htmlspecialchars($end) ?>"
        class="mt-1 block w-full border border-gray-300 rounded p-2">
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Status</label>
      <select name="status" class="mt-1 block w-full border border-gray-300 rounded p-2">
        <option value="">All</option>
        <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
        <option value="missed"   <?= $status==='missed'  ?'selected':'' ?>>Missed</option>
      </select>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700">Student</label>
      <input type="text" name="student" placeholder="Name contains…" 
        value="<?= htmlspecialchars($student) ?>"
        class="mt-1 block w-full border border-gray-300 rounded p-2">
    </div>

    <div class="col-span-4 text-right">
      <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">
        Apply Filters
      </button>
    </div>
  </form>

  <!-- Requests table -->
  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left text-sm font-medium text-gray-700">ID</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Student</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Absent</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Make-Up</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Slot</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Requested At</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Status</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-200">
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" class="p-4 text-center text-gray-500">
              No requests match your filters.
            </td>
          </tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td class="p-2 text-sm text-gray-700"><?= $r['id'] ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['student_name']) ?></td>
            <td class="p-2 text-sm text-gray-700"><?= $r['absent_date'] ?></td>
            <td class="p-2 text-sm text-gray-700"><?= $r['comp_date'] ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['slot']) ?></td>
            <td class="p-2 text-sm text-gray-700"><?= $r['requested_at'] ?></td>
            <td class="p-2 text-center">
              <?php if ($r['status'] === 'approved'): ?>
                <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">Approved</span>
              <?php else: ?>
                <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs">Missed</span>
              <?php endif; ?>
            </td>
            <td class="p-2 text-center space-x-2">
              <?php if ($r['status'] === 'approved'): ?>
                <a href="mark_missed.php?id=<?= $r['id'] ?>"
                   class="px-2 py-1 bg-red-500 text-white rounded text-xs hover:bg-red-600">
                  Mark Missed
                </a>
              <?php else: ?>
                <a href="reapprove.php?id=<?= $r['id'] ?>"
                   class="px-2 py-1 bg-green-500 text-white rounded text-xs hover:bg-green-600">
                  Re-Approve
                </a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
