<?php
// dashboard/admin/students.php
// (Assumes header_admin.php has already been included, so $conn, $page, $menu, $adminUsername are in scope)

// ─── Generate CSRF for all delete forms ─────────────────────────────
$csrf = generate_csrf_token();

// ─── Flash messages ────────────────────────────────────────────────
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// ─── Filtering / Searching / Sorting / Pagination inputs ───────────
$search  = trim($_GET['search']  ?? '');
$fc      = (int)($_GET['centre'] ?? 0);
$fp      = (int)($_GET['plan']   ?? 0);
$pnum    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$offset  = ($pnum - 1) * $perPage;

// ─── Whitelist sort keys ───────────────────────────────────────────
$allowed = [
  'id'      => 's.id',
  'name'    => 's.name',
  'email'   => 's.email',
  'phone'   => 's.phone',
  'centre'  => 'c.name',
  'group'   => 's.group_name',
];
$sk   = $_GET['sort_by']  ?? 'name';
$sd   = strtoupper($_GET['sort_dir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';
$sortBy = $allowed[$sk] ?? 's.name';

// ─── Dropdown data ─────────────────────────────────────────────────
$centresList = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$plansList = $conn
  ->query("
    SELECT
      id,
      CONCAT(plan_name,' (',duration_months,'m)') AS label
    FROM payment_plans
    ORDER BY plan_name, duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

// ─── Build WHERE + bind parameters ─────────────────────────────────
$where  = 'WHERE 1';
$params = [];
$types  = '';

if ($search !== '') {
  $where   .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.group_name LIKE ?)";
  $params  = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
  $types   .= 'sss';
}
if ($fc) {
  $where  .= " AND s.centre_id = ?";
  $params[] = $fc;
  $types  .= 'i';
}
if ($fp) {
  $where  .= " AND latest.plan_id = ?";
  $params[] = $fp;
  $types  .= 'i';
}

// ─── 1) Count total ────────────────────────────────────────────────
$sqlCount = "
  SELECT COUNT(*) AS cnt
    FROM students s
    JOIN centres c ON c.id = s.centre_id
    LEFT JOIN (
      SELECT
        ss.student_id,
        ss.plan_id,
        p.duration_months
      FROM student_subscriptions ss
      JOIN (
        SELECT student_id, MAX(subscribed_at) AS max_sub
          FROM student_subscriptions
         GROUP BY student_id
      ) mx
        ON ss.student_id = mx.student_id
       AND ss.subscribed_at = mx.max_sub
      JOIN payment_plans p
        ON p.id = ss.plan_id
    ) AS latest
      ON latest.student_id = s.id
  $where
";
$stmt = $conn->prepare($sqlCount);
if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$totalPages = (int)ceil($total / $perPage);

// ─── 2) Fetch page data ────────────────────────────────────────────
$sql = "
  SELECT
    s.id,
    s.name,
    s.email,
    s.phone,
    c.name             AS centre_name,
    s.group_name,
    COALESCE(latest.duration_months,0) AS duration_months
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  LEFT JOIN (
    SELECT
      ss.student_id,
      ss.plan_id,
      p.duration_months
    FROM student_subscriptions ss
    JOIN (
      SELECT student_id, MAX(subscribed_at) AS max_sub
        FROM student_subscriptions
       GROUP BY student_id
    ) mx
      ON ss.student_id = mx.student_id
     AND ss.subscribed_at = mx.max_sub
    JOIN payment_plans p
      ON p.id = ss.plan_id
  ) AS latest
    ON latest.student_id = s.id
  $where
  ORDER BY $sortBy $sd
  LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

// bind everything
if ($types !== '') {
  $fullTypes = $types . 'ii';
  $vals      = array_merge($params, [$perPage, $offset]);
  $refs      = [];
  $refs[]    = & $fullTypes;
  foreach ($vals as $i => $v) {
    $refs[] = & $vals[$i];
  }
  call_user_func_array([$stmt, 'bind_param'], $refs);
} else {
  $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!-- ─── UI ─────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
  <h2>Manage Students</h2>
  <a href="?page=add_student" class="btn btn-primary">
    <i class="bi bi-person-plus me-1"></i>Add Student
  </a>
</div>

<?php if ($flashSuccess): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

<form method="get" class="row g-2 mb-3">
  <input type="hidden" name="page" value="students">

  <div class="col-md-4">
    <input
      type="text"
      name="search"
      class="form-control"
      placeholder="Search name, email or group"
      value="<?= htmlspecialchars($search) ?>">
  </div>

  <div class="col-md-3">
    <select name="centre" class="form-select">
      <option value="">All Centres</option>
      <?php foreach ($centresList as $c): ?>
        <option
          value="<?= $c['id'] ?>"
          <?= $fc === $c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-3">
    <select name="plan" class="form-select">
      <option value="">All Plans</option>
      <?php foreach ($plansList as $p): ?>
        <option
          value="<?= $p['id'] ?>"
          <?= $fp === $p['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>

  <div class="col-md-2 text-end">
    <button class="btn btn-primary w-100">Filter</button>
  </div>
</form>

<div class="text-end mb-2">
  <form
    id="bulkDeleteForm"
    method="post"
    action="?page=delete_bulk"
    onsubmit="return confirm('Really delete selected students?')">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <button class="btn btn-danger">
      <i class="bi bi-trash me-1"></i>Delete Selected
    </button>
  </form>
</div>

<div class="table-responsive">
  <table class="table table-striped align-middle">
    <thead class="table-dark">
      <tr>
        <th><input type="checkbox" id="selectAll"></th>
        <?php
          function th_link($key, $lbl) {
            global $sk, $sd;
            $dir  = ($sk === $key && $sd === 'ASC') ? 'DESC' : 'ASC';
            $icon = ($sk === $key) ? ($sd === 'ASC' ? '▲' : '▼') : '';
            $qs   = http_build_query(array_merge($_GET, [
              'sort_by'  => $key,
              'sort_dir' => $dir,
              'p'        => 1
            ]));
            return "<a class=\"text-white\" href=\"?{$qs}\">"
                 . htmlspecialchars($lbl) . " {$icon}</a>";
          }
        ?>
        <th><?= th_link('id','ID') ?></th>
        <th><?= th_link('name','Name') ?></th>
        <th><?= th_link('email','Email') ?></th>
        <th><?= th_link('phone','Phone') ?></th>
        <th><?= th_link('centre','Centre') ?></th>
        <th><?= th_link('group','Group') ?></th>
        <th>Dur (m)</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($students)): ?>
        <tr><td colspan="9" class="text-center py-4">No students found.</td></tr>
      <?php else: foreach ($students as $s): ?>
        <tr>
          <td>
            <input
              type="checkbox"
              class="selectBox"
              name="student_ids[]"
              form="bulkDeleteForm"
              value="<?= $s['id'] ?>">
          </td>
          <td><?= $s['id'] ?></td>
          <td><?= htmlspecialchars($s['name']) ?></td>
          <td><?= htmlspecialchars($s['email']) ?></td>
          <td><?= htmlspecialchars($s['phone']) ?></td>
          <td><?= htmlspecialchars($s['centre_name']) ?></td>
          <td><?= htmlspecialchars($s['group_name']) ?></td>
          <td><?= $s['duration_months'] ?></td>
          <td class="d-flex gap-1">
            <a
              href="?page=edit_student&id=<?= $s['id'] ?>"
              class="btn btn-sm btn-outline-primary">Edit</a>
           <form
  method="post"
  action="?page=delete_student"
  onsubmit="return confirm('Delete this student?')">
  <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
  <input type="hidden" name="student_id" value="<?=$s['id']?>">
  <button class="btn btn-sm btn-outline-danger">Delete</button>
</form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
  <nav>
    <ul class="pagination justify-content-center">
      <li class="page-item <?= $pnum===1 ? 'disabled':'' ?>">
        <a class="page-link"
           href="?<?= http_build_query(array_merge($_GET,['p'=>$pnum-1])) ?>">
          Previous
        </a>
      </li>
      <?php for ($i=1; $i<=$totalPages; $i++): ?>
        <li class="page-item <?= $i===$pnum?'active':'' ?>">
          <a class="page-link"
             href="?<?= http_build_query(array_merge($_GET,['p'=>$i])) ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
      <li class="page-item <?= $pnum===$totalPages?'disabled':'' ?>">
        <a class="page-link"
           href="?<?= http_build_query(array_merge($_GET,['p'=>$pnum+1])) ?>">
          Next
        </a>
      </li>
    </ul>
  </nav>
<?php endif; ?>

<script>
  // “Select All” checkbox behavior
  document.getElementById('selectAll').addEventListener('change', e => {
    document.querySelectorAll('.selectBox')
      .forEach(cb => cb.checked = e.target.checked);
  });
</script>
