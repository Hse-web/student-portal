<?php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 1) Inputs: search, filters, sort, pagination
$search       = trim($_GET['search']   ?? '');
$filterCentre = (int)($_GET['centre']  ?? 0);
$filterPlan   = (int)($_GET['plan']    ?? 0);
$pageNum      = max(1, (int)($_GET['p']?? 1));
$perPage      = 20;
$offset       = ($pageNum - 1) * $perPage;

// 2) Whitelisted sort columns
$allowedSort = [
  'id'       => 's.id',
  'name'     => 's.name',
  'email'    => 's.email',
  'phone'    => 's.phone',
  'centre'   => 'c.name',
  'group'    => 's.group_name',
  'plan'     => 'lp.plan_name',
  'duration' => 'lp.duration_months',
];
$sortKey = $_GET['sort_by'] ?? 'name';
$sortBy  = $allowedSort[$sortKey] ?? 's.name';
$sortDir = (strtoupper($_GET['sort_dir'] ?? '') === 'DESC') ? 'DESC' : 'ASC';

// 3) Fetch centres & plans for the dropdowns
$centresList = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$plansList = $conn
  ->query("
    SELECT id, CONCAT(plan_name,' (',duration_months,'m)') AS label
      FROM payment_plans
     ORDER BY plan_name
  ")
  ->fetch_all(MYSQLI_ASSOC);

// 4) Build WHERE clause with parameters
$where  = 'WHERE 1';
$params = [];
$types  = '';

if ($search !== '') {
  $where .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.group_name LIKE ?)";
  $params[] = "%{$search}%";
  $params[] = "%{$search}%";
  $params[] = "%{$search}%";
  $types   .= 'sss';
}
if ($filterCentre) {
  $where .= " AND s.centre_id = ?";
  $params[] = $filterCentre;
  $types   .= 'i';
}
if ($filterPlan) {
  $where .= " AND lp.plan_id = ?";
  $params[] = $filterPlan;
  $types   .= 'i';
}

// 5) Count total filtered rows
$countSql = "
  SELECT COUNT(*) AS cnt
    FROM students s
    JOIN centres c ON c.id = s.centre_id
    LEFT JOIN (
      SELECT ss.student_id,
             p.id         AS plan_id,
             p.plan_name,
             p.duration_months
        FROM student_subscriptions ss
        JOIN payment_plans p ON p.id = ss.plan_id
       WHERE (ss.student_id, ss.subscribed_at) IN (
         SELECT student_id, MAX(subscribed_at)
           FROM student_subscriptions
          GROUP BY student_id
       )
    ) lp ON lp.student_id = s.id
  $where
";
$stmtCount = $conn->prepare($countSql);
if ($types !== '') {
    $stmtCount->bind_param($types, ...$params);
}
$stmtCount->execute();
$total = (int)$stmtCount->get_result()->fetch_assoc()['cnt'];
$stmtCount->close();

$totalPages = (int)ceil($total / $perPage);

// 6) Fetch paginated data
$sql = "
  SELECT
    s.id, s.name, s.email, s.phone,
    c.name           AS centre_name,
    s.group_name,
    COALESCE(lp.plan_name,'-')       AS plan_name,
    COALESCE(lp.duration_months,0)   AS duration_months
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  LEFT JOIN (
    SELECT ss.student_id,
           p.id         AS plan_id,
           p.plan_name,
           p.duration_months
      FROM student_subscriptions ss
      JOIN payment_plans p ON p.id = ss.plan_id
     WHERE (ss.student_id, ss.subscribed_at) IN (
       SELECT student_id, MAX(subscribed_at)
         FROM student_subscriptions
        GROUP BY student_id
     )
  ) lp ON lp.student_id = s.id
  $where
  ORDER BY $sortBy $sortDir
  LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($sql);

if ($types !== '') {
    $typesString = $types . 'ii';
    $values = array_merge([$typesString], $params, [$perPage, $offset]);
    // build references
    $refs = [];
    foreach ($values as $i => $v) {
      $refs[$i] = &$values[$i];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
} else {
    $stmt->bind_param('ii', $perPage, $offset);
}

$stmt->execute();

// ★ **THIS IS THE MISSING PIECE** ★
$result   = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

$stmt->close();

// 7) CSRF token for delete forms
$csrf = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Manage Students</title>
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container-fluid py-4">

    <!-- Flash Alerts -->
    <?php if ($flashSuccess): ?>
  <div class="alert alert-success"><?= htmlspecialchars($flashSuccess) ?></div>
<?php endif; ?>
<?php if ($flashError): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($flashError) ?></div>
<?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
      <h2>Manage Students</h2>
      <a href="index.php?page=add_student" class="btn btn-primary">
        <i class="bi bi-person-plus me-1"></i>Add Student
      </a>
    </div>

    <!-- Search & Filters -->
    <form class="row g-2 mb-3" method="get">
      <input type="hidden" name="page" value="students">
      <div class="col-md-4">
        <input type="text" name="search"
               value="<?= htmlspecialchars($search) ?>"
               class="form-control"
               placeholder="Search name, email or group">
      </div>
      <div class="col-md-3">
        <select name="centre" class="form-select">
          <option value="">All Centres</option>
          <?php foreach ($centresList as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= $filterCentre=== $c['id']?'selected':''?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="plan" class="form-select">
          <option value="">All Plans</option>
          <?php foreach ($plansList as $p): ?>
          <option value="<?= $p['id'] ?>"
            <?= $filterPlan=== $p['id']?'selected':''?>>
            <?= htmlspecialchars($p['label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 text-end">
        <button class="btn btn-primary w-100">Filter</button>
      </div>
    </form>

    <!-- Bulk Delete & Subscribe -->
    <div class="text-end mb-3">
      <form id="bulkDeleteForm" method="post" action="delete_bulk.php"
            class="d-inline-block me-2"
            onsubmit="return confirm('Delete selected?')">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <button class="btn btn-danger">
          <i class="bi bi-trash me-1"></i>Delete Selected
        </button>
      </form>
      <form id="bulkSubscribeForm" method="post" action="bulk_subscribe.php"
            class="d-inline-block"
            onsubmit="return confirm('Subscribe selected?')">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <select name="plan_id" required class="form-select d-inline-block w-auto me-1">
          <option value="">— Select Plan —</option>
          <?php foreach ($plansList as $p): ?>
            <option value="<?= $p['id'] ?>">
              <?= htmlspecialchars($p['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-secondary">
          <i class="bi bi-arrow-repeat me-1"></i>Subscribe Selected
        </button>
      </form>
    </div>

    <!-- Students Table -->
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-dark">
          <tr>
            <th><input type="checkbox" id="selectAll"></th>
            <?php
              function sortLink($k,$lbl){
                global $sortKey,$sortDir;
                $d = ($sortKey===$k&&$sortDir==='ASC')?'DESC':'ASC';
                $ic= ($sortKey===$k)?($sortDir==='ASC'?'▲':'▼'):'';
                $qs= http_build_query(array_merge($_GET,[
                  'sort_by'=>$k,'sort_dir'=>$d,'p'=>1
                ]));
                return "<a href=\"?{$qs}\" class=\"text-white\">".htmlspecialchars($lbl)." {$ic}</a>";
              }
            ?>
            <th><?=sortLink('id','ID')?></th>
            <th><?=sortLink('name','Name')?></th>
            <th><?=sortLink('email','Email')?></th>
            <th><?=sortLink('phone','Phone')?></th>
            <th><?=sortLink('centre','Centre')?></th>
            <th><?=sortLink('group','Group')?></th>
            <th><?=sortLink('plan','Plan')?></th>
            <th><?=sortLink('duration','Dur (m)')?></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($students)): ?>
            <tr><td colspan="11" class="text-center py-4">No students found.</td></tr>
          <?php else: foreach($students as $s): ?>
            <tr>
              <td><input type="checkbox" class="selectBox"
                         name="student_ids[]" form="bulkDeleteForm"
                         value="<?=$s['id']?>"></td>
              <td><?=$s['id']?></td>
              <td><?=htmlspecialchars($s['name'])?></td>
              <td><?=htmlspecialchars($s['email'])?></td>
              <td><?=htmlspecialchars($s['phone'])?></td>
              <td><?=htmlspecialchars($s['centre_name'])?></td>
              <td><?=htmlspecialchars($s['group_name'])?></td>
              <td><?=htmlspecialchars($s['plan_name'])?></td>
              <td><?=$s['duration_months']?></td>
              <td class="d-flex gap-1">
                <a href="?page=edit_student&id=<?=$s['id']?>"
                   class="btn btn-sm btn-outline-primary">Edit</a>
                <form method="post" action="delete_student.php"
                      onsubmit="return confirm('Delete this?')">
                  <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                  <input type="hidden" name="student_id" value="<?=$s['id']?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages>1): ?>
      <nav>
        <ul class="pagination justify-content-center">
          <li class="page-item <?= $pageNum===1?'disabled':'' ?>">
            <a class="page-link"
               href="?<?= http_build_query(array_merge($_GET,['p'=>$pageNum-1])) ?>">
              Previous
            </a>
          </li>
          <?php for($i=1;$i<=$totalPages;$i++): ?>
            <li class="page-item <?= $i===$pageNum?'active':'' ?>">
              <a class="page-link"
                 href="?<?= http_build_query(array_merge($_GET,['p'=>$i])) ?>">
                <?=$i?>
              </a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $pageNum===$totalPages?'disabled':'' ?>">
            <a class="page-link"
               href="?<?= http_build_query(array_merge($_GET,['p'=>$pageNum+1])) ?>">
              Next
            </a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>

  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.getElementById('selectAll').addEventListener('change',e=>{
      document.querySelectorAll('.selectBox').forEach(cb=>cb.checked=e.target.checked);
    });
  </script>
</body>
</html>
