<?php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

$selectedCentre = $_GET['centre'] ?? '';
$selectedMonth  = $_GET['month'] ?? date('Y-m');
$selectedGroup  = $_GET['group'] ?? '';

$where = '';
$params = [];
$types = '';

if ($selectedCentre !== '') {
  $where .= " AND s.centre_id = ?";
  $params[] = $selectedCentre;
  $types .= 'i';
}
if ($selectedMonth !== '') {
  $where .= " AND DATE_FORMAT(ha.date_assigned, '%Y-%m') = ?";
  $params[] = $selectedMonth;
  $types .= 's';
}
if ($selectedGroup !== '') {
  $where .= " AND s.group_name = ?";
  $params[] = $selectedGroup;
  $types .= 's';
}

$sql = "
  SELECT s.id AS student_id, s.name, s.group_name, c.name AS centre,
         ha.id AS hw_id, ha.title, ha.date_assigned,
         hs.file_path, hs.submitted_at,
         hs.feedback, hs.star_given
    FROM students s
    LEFT JOIN centres c ON s.centre_id = c.id
    LEFT JOIN homework_assigned ha ON ha.student_id = s.id
    LEFT JOIN homework_submissions hs ON hs.assignment_id = ha.id AND hs.student_id = s.id
   WHERE 1 {$where}
   ORDER BY s.name ASC, ha.date_assigned ASC
";
$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$centres = $conn->query("SELECT id, name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$groups = $conn->query("SELECT DISTINCT group_name FROM students WHERE group_name IS NOT NULL")->fetch_all();

?>
<div class="container p-4">
  <h3 class="mb-4">Homework Centerwise Review</h3>

  <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
    <div class="alert alert-success">✅ Feedback saved successfully!</div>
  <?php endif; ?>

  <form class="row mb-4" method="get">
    <input type="hidden" name="page" value="homework_centerwise">
    <div class="col-md-3">
      <label>Centre</label>
      <select name="centre" class="form-select">
        <option value="">All</option>
        <?php foreach ($centres as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $selectedCentre == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label>Group</label>
      <select name="group" class="form-select">
        <option value="">All</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= $g[0] ?>" <?= $selectedGroup == $g[0] ? 'selected' : '' ?>>
            <?= htmlspecialchars($g[0]) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label>Month</label>
      <input type="month" name="month" value="<?= htmlspecialchars($selectedMonth) ?>" class="form-control">
    </div>
    <div class="col-md-3 d-flex align-items-end">
      <button class="btn btn-primary">Apply Filters</button>
    </div>
  </form>

  <form method="post" action="save_bulk_feedback.php">
    <table class="table table-bordered table-sm align-middle">
      <thead>
        <tr>
          <th>Student</th>
          <th>Homework</th>
          <th>Thumbnail</th>
          <th>Submitted</th>
          <th>Stars</th>
          <th>Feedback</th>
          <th><input type="checkbox" id="checkAll"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($data as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['name']) ?><br><small><?= $row['group_name'] ?></small></td>
          <td><?= htmlspecialchars($row['title']) ?><br><small><?= $row['date_assigned'] ?></small></td>
          <td>
            <?php if (!empty($row['file_path']) && file_exists("../../" . $row['file_path'])): ?>
              <img src="/<?= $row['file_path'] ?>" width="80">
            <?php else: ?> —
            <?php endif; ?>
          </td>
          <td><?= $row['submitted_at'] ? '✅' : '❌' ?></td>
          <td><?= $row['star_given'] ? "⭐ {$row['star_given']}" : '—' ?></td>
          <td>
            <textarea name="feedback[<?= $row['student_id'] ?>][<?= $row['hw_id'] ?>]" class="form-control" rows="2"><?= htmlspecialchars($row['feedback']) ?></textarea>
          </td>
          <td>
            <input type="checkbox" class="check-row" name="selected[]" value="<?= $row['student_id'] ?>-<?= $row['hw_id'] ?>">
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <button class="btn btn-success mt-2">Save Selected Feedback</button>
  </form>
</div>

<script>
  document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('.check-row').forEach(cb => cb.checked = this.checked);
  });
</script>
