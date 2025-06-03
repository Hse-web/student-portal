<?php
// File: dashboard/admin/comp_requests.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── Generate CSRF (if you have forms) ──────────────────────────────
$csrf = generate_csrf_token();

// ─── Example: Load all “compensation requests” from DB ──────────────
$requests = $conn
  ->query("
   SELECT
    c.id,
    IFNULL(DATE_FORMAT(c.comp_date,'%Y-%m-%d'), '-')
      AS comp_date,
    s.name AS student_name,
    DATE_FORMAT(c.requested_at,'%Y-%m-%d %h:%i %p') AS requested_at,
    c.status
  FROM compensation_requests c
  JOIN students s ON s.user_id=c.user_id
  ORDER BY c.comp_date DESC, c.requested_at DESC
  ")
  ->fetch_all(MYSQLI_ASSOC);

// (If you have POST to approve/deny, handle it above)
?>
<div class="max-w-6xl mx-auto p-6 space-y-6">
  <h2 class="text-2xl font-bold text-gray-800">📄 Compensation Requests</h2>

  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left text-sm font-medium text-gray-700">ID</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Student</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Requested At</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Reason</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Status</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($requests)): ?>
          <tr>
            <td colspan="6" class="p-4 text-center text-gray-500">
              No compensation requests found.
            </td>
          </tr>
        <?php else: foreach ($requests as $r): ?>
          <tr>
            <td class="p-2 text-sm text-gray-700"><?= $r['id'] ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['student_name']) ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['requested_at']) ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['reason']) ?></td>
            <td class="p-2 text-center text-sm">
              <?php if ($r['status'] === 'approved'): ?>
                <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">Approved</span>
              <?php elseif ($r['status'] === 'denied'): ?>
                <span class="px-2 py-1 bg-red-100 text-red-800 rounded text-xs">Denied</span>
              <?php else: ?>
                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded text-xs">Pending</span>
              <?php endif; ?>
            </td>
            <td class="p-2 text-center space-x-2">
              <!-- Example action buttons (approve/deny) -->
              <button class="px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600">
                Approve
              </button>
              <button class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600">
                Deny
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
