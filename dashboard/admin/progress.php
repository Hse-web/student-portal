<?php
// File: dashboard/admin/progress.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─────────────────────────────────────────────────────────────────────────
// 1) Load all remark-template rows and group by category_key and grade
//    (Assumes your remark_templates table has columns: category_key, grade, text)
// ─────────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
  SELECT category_key, grade, text
    FROM remark_templates
");
$stmt->execute();
$tplRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// organize as templates[category_key][grade] = [ ...texts ]
$templates = [];
foreach ($tplRows as $r) {
  $templates[$r['category_key']][$r['grade']][] = $r['text'];
}

// ─────────────────────────────────────────────────────────────────────────
// 2) Flash & CSRF
// ─────────────────────────────────────────────────────────────────────────
$flash     = $_SESSION['flash_success'] ?? $_SESSION['flash_error'] ?? null;
$flashType = isset($_SESSION['flash_success']) ? 'success' : 'danger';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$csrf = generate_csrf_token();

// ─────────────────────────────────────────────────────────────────────────
// 3) Definitions
// ─────────────────────────────────────────────────────────────────────────
$categories = [
  'hand_control'     => 'Hand Control',
  'coloring_shading' => 'Coloring & Shading',
  'observations'     => 'Observations',
  'temperament'      => 'Temperament',
  'attendance'       => 'Attendance',
  'homework'         => 'Homework',
];
$remarkCols = [
  'hand_control'     => 'hc_remark',
  'coloring_shading' => 'cs_remark',
  'observations'     => 'obs_remark',
  'temperament'      => 'temp_remark',
  'attendance'       => 'att_remark',
  'homework'         => 'hw_remark',
];
$grades = [
  '1' => 'Needs Improvement',
  '2' => 'Average',
  '3' => 'Good',
  '4' => 'Very Good',
  '5' => 'Excellent',
];

// ─────────────────────────────────────────────────────────────────────────
// 4) Handle Add/Edit submission
// ─────────────────────────────────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $errors[] = 'Session expired—please refresh and try again.';
  } else {
    $id      = (int)($_POST['id'] ?? 0);
    $student = (int)($_POST['student_id'] ?? 0);
    $month   = trim($_POST['month'] ?? '');

    if (!$student) {
      $errors[] = 'Please select a student.';
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
      $errors[] = 'Please select a valid month.';
    }

    // Validate grade+remark combos
    $data = [];
    foreach ($categories as $key => $label) {
      $g = $_POST[$key] ?? '';
      if (!isset($grades[$g])) {
        $errors[] = "Select a grade for {$label}.";
      }
      $r = trim($_POST[$remarkCols[$key]] ?? '');
      if ($r === '') {
        $errors[] = "Enter a remark for {$label}.";
      }
      $data[$key]              = $g;
      $data[$remarkCols[$key]] = $r;
    }

    // If no errors, INSERT or UPDATE
    if (!$errors) {
      if ($id) {
        // UPDATE
        $sql   = 'UPDATE progress SET student_id=?, month=?';
        $types = 'is';
        $vals  = [$student, $month];
        foreach ($categories as $key => $_) {
          $sql    .= ", {$key}=?, {$remarkCols[$key]}=?";
          $types  .= 'ss';
          $vals[]  = $data[$key];
          $vals[]  = $data[$remarkCols[$key]];
        }
        $sql   .= ' WHERE id=?';
        $types .= 'i';
        $vals[] = $id;
      } else {
        // INSERT
        $fields       = ['student_id','month'];
        $placeholders = ['?','?'];
        $types        = 'is';
        $vals         = [$student,$month];
        foreach ($categories as $key => $_) {
          $fields[]       = $key;
          $fields[]       = $remarkCols[$key];
          $placeholders[] = '?';
          $placeholders[] = '?';
          $types         .= 'ss';
          $vals[]         = $data[$key];
          $vals[]         = $data[$remarkCols[$key]];
        }
        $sql = 'INSERT INTO progress ('.
               implode(',', $fields).
               ') VALUES ('.
               implode(',', $placeholders).
               ')';
      }

      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $stmt->close();

      $_SESSION['flash_success'] = $id
        ? "Progress #{$id} updated."
        : 'Progress record added.';
      header('Location: index.php?page=progress');
      exit;
    }
  }
}

// ─────────────────────────────────────────────────────────────────────────
// 5) If editing, load that record
// ─────────────────────────────────────────────────────────────────────────
$edit = null;
if (isset($_GET['edit'])) {
  $eid = (int)$_GET['edit'];
  if ($eid > 0) {
    $stmt = $conn->prepare('SELECT * FROM progress WHERE id=? LIMIT 1');
    $stmt->bind_param('i',$eid);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
  } else {
    $edit = []; // new
  }
}

// ─────────────────────────────────────────────────────────────────────────
// 6) Listing & filters
// ─────────────────────────────────────────────────────────────────────────
$filterStudent = (int)($_GET['student_id'] ?? 0);
$filterMonth   = trim($_GET['month'] ?? '');
if ($filterMonth && !preg_match('/^\d{4}-\d{2}$/',$filterMonth)) {
  $filterMonth='';
}

// build WHERE clauses
$where = []; $params = []; $types = '';
if ($filterStudent) {
  $where[] = 'p.student_id=?';
  $types  .= 'i';
  $params[]= $filterStudent;
}
if ($filterMonth) {
  $where[] = "DATE_FORMAT(p.month,'%Y-%m')=?";
  $types  .= 's';
  $params[]= $filterMonth;
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// pagination
$perPage = 15;
$pageNo  = max(1,(int)($_GET['page_no']??1));
$offset  = ($pageNo-1)*$perPage;

// total count
$stmt = $conn->prepare("SELECT COUNT(*) FROM progress p $whereSql");
if ($where) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$stmt->bind_result($totalCount);
$stmt->fetch();
$stmt->close();
$totalPages = max(1,ceil($totalCount/$perPage));

// fetch list
$cols = 'p.id,p.month,s.name AS student_name';
foreach ($categories as $key => $_) {
  $cols .= ",p.{$key},p.{$remarkCols[$key]}";
}
$sql = "
  SELECT {$cols}
    FROM progress p
    JOIN students s ON s.id=p.student_id
    {$whereSql}
  ORDER BY p.month DESC,s.name
  LIMIT ?,?
";
$stmt = $conn->prepare($sql);
if ($where) {
  $stmt->bind_param($types.'ii', ...array_merge($params,[$offset,$perPage]));
} else {
  $stmt->bind_param('ii',$offset,$perPage);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// student list
$students = $conn->query('SELECT id,name FROM students ORDER BY name')->fetch_all(MYSQLI_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Progress Manager</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
</head>
<body class="bg-light">
  <div class="container py-5">

    <!-- FLASH -->
    <?php if($flash): ?>
      <div class="alert alert-<?= $flashType ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
      <h1>Progress Manager</h1>
      <a href="?page=progress&edit=0" class="btn btn-primary">+ Add Progress</a>
    </div>

    <!-- ADD / EDIT FORM -->
    <?php if($edit !== null): ?>
      <div class="card mb-5">
        <div class="card-body">
          <h5 class="card-title"><?= $edit ? 'Edit' : 'Add' ?> Progress</h5>
          <?php if($errors): ?>
            <div class="alert alert-danger">
              <ul class="mb-0">
                <?php foreach($errors as $e): ?>
                  <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="id" value="<?= intval($edit['id'] ?? 0) ?>">

            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label">Student</label>
                <select name="student_id" class="form-select" required>
                  <option value="">Select…</option>
                  <?php foreach($students as $s): ?>
                    <option value="<?= $s['id'] ?>"
                      <?= (int)($edit['student_id']??0)===$s['id']?'selected':''?>>
                      <?= htmlspecialchars($s['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Month</label>
                <input type="month" name="month" class="form-control"
                       value="<?= htmlspecialchars($edit['month'] ?? '') ?>" required>
              </div>
            </div>

            <?php foreach($categories as $key=>$label): ?>
              <div class="row mb-3 align-items-end">
                <div class="col-md-3">
                  <label class="form-label"><?= $label ?> Grade</label>
                  <select name="<?= $key ?>"
                          data-cat="<?= $key ?>"
                          class="form-select metric-grade"
                          required>
                    <option value="">—</option>
                    <?php foreach($grades as $gv=>$gt): ?>
                      <option value="<?= $gv ?>"
                        <?= ($edit[$key] ?? '')===$gv?'selected':''?>>
                        <?= $gt ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-9">
                  <label class="form-label"><?= $label ?> Remark</label>
                  <input type="text"
                         name="<?= $remarkCols[$key] ?>"
                         data-cat="<?= $key ?>"
                         class="form-control metric-remark"
                         value="<?= htmlspecialchars($edit[$remarkCols[$key]] ?? '') ?>"
                         required>
                </div>
              </div>
            <?php endforeach; ?>

            <button class="btn btn-success"><?= $edit ? 'Save Changes' : 'Submit' ?></button>
            <a href="?page=progress" class="btn btn-secondary ms-2">Cancel</a>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- FILTERS -->
    <form method="GET" class="row g-3 mb-4">
      <input type="hidden" name="page" value="progress">
      <div class="col-md-3">
        <label class="form-label">Student</label>
        <select name="student_id" class="form-select">
          <option value="">All Students</option>
          <?php foreach($students as $s): ?>
            <option value="<?= $s['id'] ?>"
              <?= $filterStudent===$s['id']?'selected':''?>>
              <?= htmlspecialchars($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Month</label>
        <input type="month" name="month" class="form-control"
               value="<?= htmlspecialchars($filterMonth) ?>">
      </div>
      <div class="col-md-6 d-flex align-items-end">
        <button class="btn btn-primary me-2">Apply</button>
        <a href="?page=progress" class="btn btn-outline-secondary">Reset</a>
      </div>
    </form>

    <!-- LIST TABLE -->
    <div class="table-responsive mb-4">
      <table class="table table-striped">
        <thead class="table-light">
          <tr>
            <th>Month</th>
            <th>Student</th>
            <?php foreach($categories as $label): ?>
              <th><?= $label ?></th>
            <?php endforeach; ?>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if(empty($rows)): ?>
            <tr><td colspan="<?= count($categories)+3 ?>" class="text-center">No records found.</td></tr>
          <?php else: ?>
            <?php foreach($rows as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['month']) ?></td>
                <td><?= htmlspecialchars($r['student_name']) ?></td>
                <?php foreach(array_keys($categories) as $key): ?>
                  <td>
                    <?= $grades[$r[$key]] ?? '—' ?><br>
                    <small class="text-muted">
                      <?= htmlspecialchars($r[$remarkCols[$key]]) ?>
                    </small>
                  </td>
                <?php endforeach; ?>
                <td>
                  <a href="?page=progress&edit=<?= $r['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                  <a href="?page=progress&delete=<?= $r['id'] ?>"
                     onclick="return confirm('Delete this record?')"
                     class="btn btn-sm btn-danger">Delete</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- PAGINATION -->
    <?php if($totalPages>1): ?>
      <nav>
        <ul class="pagination">
          <?php for($p=1;$p<=$totalPages;$p++): ?>
            <li class="page-item <?= $p===$pageNo?'active':'' ?>">
              <a class="page-link"
                 href="?page=progress&student_id=<?= $filterStudent ?>&month=<?= urlencode($filterMonth) ?>&page_no=<?= $p ?>">
                <?= $p ?>
              </a>
            </li>
          <?php endfor; ?>
        </ul>
      </nav>
    <?php endif; ?>

  </div>

  <!-- 7) Expose templates to JavaScript -->
  <script>
    window.remarkTemplates = <?= json_encode($templates, JSON_UNESCAPED_UNICODE) ?>;
  </script>

  <!-- 8) Auto‐fill remark on grade change by data-cat -->
  <script>
  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('.metric-grade').forEach(sel=>{
      sel.addEventListener('change', ()=>{
        const cat   = sel.getAttribute('data-cat');
        const grade = sel.value;
        const bucket= (window.remarkTemplates[cat]||{})[grade]||[];
        if (!bucket.length) return;
        const remark = bucket[Math.floor(Math.random()*bucket.length)];
        const input  = document.querySelector(`.metric-remark[data-cat="${cat}"]`);
        if (input) input.value = remark;
      });
    });
  });
  </script>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
