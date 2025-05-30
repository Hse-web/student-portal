<?php
// File: dashboard/admin/students.php

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
  'id'     => 's.id',
  'name'   => 's.name',
  'email'  => 's.email',
  'phone'  => 's.phone',
  'centre' => 'c.name',
  'group'  => 's.group_name'
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
    SELECT id,
           CONCAT(plan_name,' (',duration_months,'m)') AS label
      FROM payment_plans
     ORDER BY plan_name, duration_months
  ")
  ->fetch_all(MYSQLI_ASSOC);

// ─── Build WHERE + bind params ──────────────────────────────────────
$where  = 'WHERE 1';
$params = [];
$types  = '';

if ($search !== '') {
  $where   .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.group_name LIKE ?)";
  $params  = array_merge($params, ["%{$search}%", "%{$search}%", "%{$search}%"]);
  $types   .= 'sss';
}
if ($fc) {
  $where   .= " AND s.centre_id = ?";
  $params[] = $fc;
  $types   .= 'i';
}
if ($fp) {
  $where   .= " AND latest.plan_id = ?";
  $params[] = $fp;
  $types   .= 'i';
}

// ─── 1) Count total ────────────────────────────────────────────────
$sqlCount = "
  SELECT COUNT(*) AS cnt
    FROM students s
    JOIN centres c ON c.id = s.centre_id
    LEFT JOIN (
      SELECT ss.student_id, ss.plan_id, p.duration_months
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
    ) latest ON latest.student_id = s.id
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
    s.id, s.name, s.email, s.phone,
    c.name AS centre_name,
    s.group_name,
    COALESCE(latest.duration_months,0) AS duration_months
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  LEFT JOIN (
    SELECT ss.student_id, ss.plan_id, p.duration_months
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
  ) latest ON latest.student_id = s.id
  $where
  ORDER BY $sortBy $sd
  LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

// bind params + pagination
if ($types !== '') {
  $fullTypes = $types . 'ii';
  $vals      = array_merge($params, [$perPage, $offset]);
  $refs      = [&$fullTypes];
  foreach ($vals as $i => $v) {
    $refs[] = &$vals[$i];
  }
  call_user_func_array([$stmt, 'bind_param'], $refs);
} else {
  $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!-- ─── UI START ───────────────────────────────────────────────────── -->

<div class="flex justify-between items-center mb-6">
  <h2 class="text-2xl font-semibold text-gray-800">Manage Students</h2>
  <a href="?page=add_student"
     class="inline-flex items-center px-4 py-2 bg-admin-primary text-white rounded-lg
            hover:bg-opacity-90 transition">
    <i class="bi bi-person-plus mr-2"></i>
    Add Student
  </a>
</div>

<?php if ($flashSuccess): ?>
  <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
    <?= htmlspecialchars($flashSuccess) ?>
  </div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
    <?= htmlspecialchars($flashError) ?>
  </div>
<?php endif; ?>

<form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
  <input type="hidden" name="page" value="students" />

  <input name="search"
         type="text"
         placeholder="Search name, email or group"
         value="<?= htmlspecialchars($search) ?>"
         class="p-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-admin-primary" />

  <select name="centre"
          class="p-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-admin-primary">
    <option value="">All Centres</option>
    <?php foreach ($centresList as $c): ?>
      <option value="<?= $c['id'] ?>"
        <?= $fc === $c['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($c['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <select name="plan"
          class="p-2 border border-gray-300 rounded focus:outline-none focus:ring focus:border-admin-primary">
    <option value="">All Plans</option>
    <?php foreach ($plansList as $p): ?>
      <option value="<?= $p['id'] ?>"
        <?= $fp === $p['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($p['label']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <button type="submit"
          class="px-4 py-2 bg-admin-primary text-white rounded-lg hover:bg-opacity-90 transition">
    Filter
  </button>
</form>

<div class="mb-4 text-right">
  <form id="bulkDeleteForm"
        method="post"
        action="?page=delete_bulk"
        onsubmit="return confirm('Really delete selected students?')">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>" />
    <button type="submit"
            class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg
                   hover:bg-red-700 transition">
      <i class="bi bi-trash mr-2"></i>
      Delete Selected
    </button>
  </form>
</div>

<div class="overflow-x-auto">
  <table class="min-w-full bg-white divide-y divide-gray-200">
    <thead class="bg-gray-50">
      <tr>
        <th class="px-4 py-2 text-left">
          <input type="checkbox" id="selectAll" class="text-gray-600" />
        </th>

        <?php
        // helper to emit sortable headers
        function th_link($key, $lbl) {
          global $sk, $sd;
          $dir  = ($sk === $key && $sd === 'ASC') ? 'DESC' : 'ASC';
          $icon = ($sk === $key)
            ? ($sd === 'ASC' ? '▲' : '▼')
            : '';
          $qs   = http_build_query(array_merge($_GET, [
            'sort_by'  => $key,
            'sort_dir' => $dir,
            'p'        => 1
          ]));
          return "<a href=\"?{$qs}\" class=\"inline-flex items-center space-x-1 text-gray-700 hover:text-gray-900\">"
               . "<span>{$lbl}</span><span class=\"text-sm\">{$icon}</span></a>";
        }
        ?>

        <th class="px-4 py-2"><?= th_link('id','ID') ?></th>
        <th class="px-4 py-2"><?= th_link('name','Name') ?></th>
        <th class="px-4 py-2"><?= th_link('email','Email') ?></th>
        <th class="px-4 py-2"><?= th_link('phone','Phone') ?></th>
        <th class="px-4 py-2"><?= th_link('centre','Centre') ?></th>
        <th class="px-4 py-2"><?= th_link('group','Group') ?></th>
        <th class="px-4 py-2 text-left">Dur (m)</th>
        <th class="px-4 py-2 text-left">Actions</th>
      </tr>
    </thead>

    <tbody class="bg-white divide-y divide-gray-200">
      <?php if (empty($students)): ?>
        <tr>
          <td colspan="9" class="px-4 py-6 text-center text-gray-500">
            No students found.
          </td>
        </tr>
      <?php else: foreach ($students as $s): ?>
        <tr>
          <td class="px-4 py-2 whitespace-nowrap">
            <input type="checkbox"
                   class="text-gray-600 selectBox"
                   name="student_ids[]"
                   form="bulkDeleteForm"
                   value="<?= $s['id'] ?>" />
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= $s['id'] ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= htmlspecialchars($s['name']) ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= htmlspecialchars($s['email']) ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= htmlspecialchars($s['phone']) ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= htmlspecialchars($s['centre_name']) ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= htmlspecialchars($s['group_name']) ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">
            <?= $s['duration_months'] ?>
          </td>
          <td class="px-4 py-2 whitespace-nowrap flex space-x-2">
            <a href="?page=edit_student&id=<?= $s['id'] ?>"
               class="px-2 py-1 border border-admin-primary text-admin-primary rounded
                      hover:bg-admin-primary hover:text-white transition">
              Edit
            </a>
            <form method="post"
                  action="?page=delete_student"
                  onsubmit="return confirm('Delete this student?')">
              <input type="hidden" name="csrf_token"
                     value="<?= htmlspecialchars($csrf) ?>" />
              <input type="hidden" name="student_id"
                     value="<?= $s['id'] ?>" />
              <button type="submit"
                      class="px-2 py-1 border border-red-600 text-red-600 rounded
                             hover:bg-red-600 hover:text-white transition">
                Delete
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
  <nav class="mt-6 flex justify-center">
    <ul class="inline-flex -space-x-px">
      <!-- Previous -->
      <li>
        <a href="?<?= http_build_query(array_merge($_GET, ['p'=>max(1,$pnum-1)])) ?>"
           class="px-3 py-1 border border-gray-300 text-gray-500 rounded-l hover:bg-gray-100">
          Previous
        </a>
      </li>
      <!-- Page numbers -->
      <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <li>
          <a href="?<?= http_build_query(array_merge($_GET, ['p'=>$i])) ?>"
             class="px-3 py-1 border border-gray-300 <?= $i === $pnum
                ? 'bg-admin-primary text-white'
                : 'text-gray-700 hover:bg-gray-100' ?>">
            <?= $i ?>
          </a>
        </li>
      <?php endfor; ?>
      <!-- Next -->
      <li>
        <a href="?<?= http_build_query(array_merge($_GET, ['p'=>min($totalPages,$pnum+1)])) ?>"
           class="px-3 py-1 border border-gray-300 text-gray-500 rounded-r hover:bg-gray-100">
          Next
        </a>
      </li>
    </ul>
  </nav>
<?php endif; ?>

<script>
// “Select All” functionality
document.getElementById('selectAll')
        .addEventListener('change', e => {
  document.querySelectorAll('.selectBox')
          .forEach(cb => cb.checked = e.target.checked);
});
</script>
