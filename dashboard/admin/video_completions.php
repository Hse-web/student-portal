<?php
// File: dashboard/admin/video_completions.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── Generate CSRF (for delete) ───────────────────────────────────
$csrf = generate_csrf_token();

// ─── Fetch completions ────────────────────────────────────────────
$completions = $conn->query("
  SELECT
    vc.id                 AS completion_id,
    s.name                AS student_name,
    CONCAT('Class on ', v.class_date) AS video_title,
    vc.watched_at         AS completed_at
  FROM video_completions vc
  JOIN students s   ON s.id = vc.student_id
  JOIN compensation_videos v ON v.id = vc.video_id
  ORDER BY vc.watched_at DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<div class="max-w-6xl mx-auto p-6 space-y-6">
  <h2 class="text-2xl font-bold text-gray-800">📺 Video Completions</h2>

  <div class="overflow-x-auto bg-white rounded shadow">
    <table class="min-w-full divide-y divide-gray-200">
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
        <?php else: foreach ($completions as $row): ?>
          <tr>
            <td class="p-2 text-sm text-gray-800"><?= (int)$row['completion_id'] ?></td>
            <td class="p-2 text-sm text-gray-800"><?= htmlspecialchars($row['student_name']) ?></td>
            <td class="p-2 text-sm text-gray-800"><?= htmlspecialchars($row['video_title']) ?></td>
            <td class="p-2 text-sm text-gray-800"><?= htmlspecialchars($row['completed_at']) ?></td>
            <td class="p-2 text-center space-x-2">
              <!-- View button (if you have a detail page) -->
              <a href="index.php?page=view_completion&id=<?= (int)$row['completion_id'] ?>"
                 class="px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                View
              </a>

              <!-- Delete form -->
              <form method="POST" class="inline-block"
                    onsubmit="return confirm('Delete this completion?');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action"     value="delete_completion">
                <input type="hidden" name="id"         value="<?= (int)$row['completion_id'] ?>">
                <button type="submit"
                        class="px-3 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">
                  Delete
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
