<?php
// File: dashboard/admin/admin_payment.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── Generate CSRF (if you have forms here) ─────────────────────────
$csrf = generate_csrf_token();

// ─── (Example) Load all payments to display or process
$payments = $conn
  ->query("
   SELECT pp.id, pp.student_id, pp.file_path, pp.uploaded_at, s.name AS student_name, s.group_name
    FROM payment_proofs pp
    JOIN students s ON s.id = pp.student_id
   WHERE pp.status = 'Pending'
   ORDER BY pp.uploaded_at DESC
  ")
  ->fetch_all(MYSQLI_ASSOC);

// ─── (Example) If you have POST actions (approve payment, delete, etc.), handle them above with CSRF check.
// For this sample, we’ll just show a table of payments.
?>
<div class="max-w-6xl mx-auto p-6 space-y-6">
  <h2 class="text-2xl font-bold text-gray-800">💰 Manage Payments</h2>

  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left text-sm font-medium text-gray-700">ID</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Student</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Status</th>
          <th class="p-2 text-right text-sm font-medium text-gray-700">Paid</th>
          <th class="p-2 text-right text-sm font-medium text-gray-700">Due</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Paid At</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($payments)): ?>
          <tr>
            <td colspan="7" class="p-4 text-center text-gray-500">
              No payment records found.
            </td>
          </tr>
        <?php else: foreach ($payments as $r): ?>
          <tr>
            <td class="p-2 text-sm text-gray-700"><?= $r['id'] ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['student_name']) ?></td>
            <td class="p-2 text-center text-sm">
              <?php if ($r['status'] === 'Paid'): ?>
                <span class="px-2 py-1 bg-green-100 text-green-800 rounded text-xs">Paid</span>
              <?php else: ?>
                <span class="px-2 py-1 bg-yellow-100 text-yellow-800 rounded text-xs"><?= htmlspecialchars($r['status']) ?></span>
              <?php endif; ?>
            </td>
            <td class="p-2 text-right text-sm text-gray-700"><?= number_format($r['amount_paid'], 2) ?></td>
            <td class="p-2 text-right text-sm text-gray-700"><?= number_format($r['amount_due'], 2) ?></td>
            <td class="p-2 text-left text-sm text-gray-700">
              <?= $r['paid_at'] ? htmlspecialchars($r['paid_at']) : '—' ?>
            </td>
            <td class="p-2 text-center space-x-2">
              <!-- Example action: “Mark as Paid” or “View Details” -->
              <button class="px-2 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600">
                View
              </button>
              <button class="px-2 py-1 bg-red-500 text-white text-xs rounded hover:bg-red-600">
                Delete
              </button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
