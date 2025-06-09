<?php
// File: dashboard/admin/video_completions.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── Generate CSRF (if you have forms) ──────────────────────────────
$csrf = generate_csrf_token();

// ─── Example: Load video completion stats from DB ───────────────────
$completions = $conn
  ->query("
    SELECT vc.id,s.name AS student,v.class_date,vc.watched_at
     FROM video_completions vc
     JOIN students s ON s.id=vc.student_id
     JOIN compensation_videos v ON v.id=vc.video_id
    ORDER BY vc.watched_at DESC
  ")
  ->fetch_all(MYSQLI_ASSOC);
?>
<div class="max-w-6xl mx-auto p-6 space-y-6">
  <h2 class="text-2xl font-bold text-gray-800">📺 Video Completions</h2>

  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="p-2 text-left text-sm font-medium text-gray-700">ID</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Student</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Video</th>
          <th class="p-2 text-left text-sm font-medium text-gray-700">Completed At</th>
          <th class="p-2 text-center text-sm font-medium text-gray-700">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <?php if (empty($completions)): ?>
          <tr>
            <td colspan="5" class="p-4 text-center text-gray-500">
              No video completions found.
            </td>
          </tr>
        <?php else: foreach ($completions as $r): ?>
          <tr>
            <td class="p-2 text-sm text-gray-700"><?= $r['id'] ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['student_name']) ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['video_title']) ?></td>
            <td class="p-2 text-sm text-gray-700"><?= htmlspecialchars($r['completed_at']) ?></td>
            <td class="p-2 text-center space-x-2">
              <!-- Example: “View” or “Remove” -->
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
