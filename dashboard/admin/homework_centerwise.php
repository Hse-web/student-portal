<?php
// File: dashboard/admin/homework_centerwise.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── Generate CSRF token (for any “bulk feedback” forms) ─────────────
$csrf = generate_csrf_token();

// ─── Filters (centre, group, month) ─────────────────────────────────
$centre = $_GET['centre'] ?? '';
$group  = $_GET['group']  ?? '';
$month  = $_GET['month']  ?? date('Y-m');

$where  = 'WHERE 1';
$params = [];
$types  = '';

if ($centre !== '') {
  $where   .= " AND s.centre_id = ?";
  $params[] = (int)$centre;
  $types   .= 'i';
}
if ($group !== '') {
  $where   .= " AND s.group_name = ?";
  $params[] = $group;
  $types   .= 's';
}
if ($month !== '') {
  $where   .= " AND DATE_FORMAT(ha.date_assigned,'%Y-%m') = ?";
  $params[] = $month;
  $types   .= 's';
}

// ─── Fetch “review” data (left join assigned & submissions) ────────────
$sql = "
  SELECT
    s.id          AS student_id,
    s.name,
    s.group_name,
    c.name        AS centre,
    ha.id         AS hw_id,
    ha.title,
    ha.date_assigned,
    hs.file_path,
    hs.submitted_at,
    hs.feedback,
    hs.star_given
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  LEFT JOIN homework_assigned ha ON ha.student_id = s.id
  LEFT JOIN homework_submissions hs
    ON hs.assignment_id = ha.id
   AND hs.student_id    = s.id
  $where
  ORDER BY s.name ASC, ha.date_assigned ASC
";
$stmt = $conn->prepare($sql);
if ($types) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Load dropdown lists for “centre” & “group” ───────────────────────
$centresList = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$groupsList = $conn
  ->query("SELECT DISTINCT group_name FROM students ORDER BY group_name")
  ->fetch_all(MYSQLI_NUM); // each row is a 1‐element numeric array
?>
<div class="max-w-5xl mx-auto p-6 space-y-6">
  <h2 class="text-2xl font-bold text-gray-800">✏️ Homework Centerwise Review</h2>

  <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <input type="hidden" name="page" value="homework_centerwise">

    <div>
      <label class="block text-gray-700 mb-1">Centre</label>
      <select name="centre"
              class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary">
        <option value="">All Centres</option>
        <?php foreach ($centresList as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= $centre == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Group</label>
      <select name="group"
              class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary">
        <option value="">All Groups</option>
        <?php foreach ($groupsList as [$g]): ?>
          <option value="<?= htmlspecialchars($g) ?>"
            <?= $group == $g ? 'selected' : '' ?>>
            <?= htmlspecialchars($g) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="block text-gray-700 mb-1">Month</label>
      <input type="month"
             name="month"
             value="<?= htmlspecialchars($month) ?>"
             class="w-full border border-gray-300 rounded px-3 py-2 focus:ring focus:border-admin-primary">
    </div>

    <div class="flex items-end">
      <button type="submit"
              class="w-full px-4 py-2 bg-admin-primary text-white rounded-lg hover:opacity-90 transition">
        Apply Filters
      </button>
    </div>
  </form>

  <div class="overflow-x-auto">
    <table class="min-w-full bg-white divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Student</th>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Homework</th>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Thumbnail</th>
          <th class="px-3 py-2 text-center text-sm font-medium text-gray-700">Submitted</th>
          <th class="px-3 py-2 text-center text-sm font-medium text-gray-700">Stars</th>
          <th class="px-3 py-2 text-left text-sm font-medium text-gray-700">Feedback</th>
          <th class="px-3 py-2 text-center text-sm font-medium text-gray-700">
            <input type="checkbox" id="checkAll" class="text-gray-600">
          </th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if (empty($data)): ?>
          <tr>
            <td colspan="7" class="px-4 py-6 text-center text-gray-500">
              No records found.
            </td>
          </tr>
        <?php else: foreach ($data as $row): ?>
          <tr>
            <td class="px-3 py-2 text-sm text-gray-700">
              <?= htmlspecialchars($row['name']) ?><br>
              <span class="text-xs text-gray-500"><?= htmlspecialchars($row['group_name']) ?></span>
            </td>
            <td class="px-3 py-2 text-sm text-gray-700">
              <?= htmlspecialchars($row['title']) ?><br>
              <span class="text-xs text-gray-500"><?= htmlspecialchars($row['date_assigned']) ?></span>
            </td>
            <td class="px-3 py-2">
              <?php if ($row['file_path'] && file_exists(__DIR__ . '/../../' . $row['file_path'])): ?>
                <img src="/<?= htmlspecialchars($row['file_path']) ?>"
                     class="w-16 h-16 object-cover rounded">
              <?php else: ?>
                &mdash;
              <?php endif; ?>
            </td>
            <td class="px-3 py-2 text-center"><?= $row['submitted_at'] ? '✅' : '❌' ?></td>
            <td class="px-3 py-2 text-center"><?= $row['star_given'] ? "⭐ {$row['star_given']}" : '—' ?></td>
            <td class="px-3 py-2">
              <textarea
                name="feedback[<?= $row['student_id'] ?>][<?= $row['hw_id'] ?>]"
                rows="2"
                class="w-full border border-gray-300 rounded px-2 py-1 focus:ring focus:border-admin-primary"
              ><?= htmlspecialchars($row['feedback']) ?></textarea>
            </td>
            <td class="px-3 py-2 text-center">
              <input type="checkbox"
                     class="check-row text-gray-600"
                     value="<?= $row['student_id'] . '-' . $row['hw_id'] ?>">
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  document.getElementById('checkAll')
          .addEventListener('change', e => {
    document.querySelectorAll('.check-row')
            .forEach(cb => cb.checked = e.target.checked);
  });
</script>
