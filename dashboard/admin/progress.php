<?php
// File: dashboard/admin/progress.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';

// ─── Auth guard ─────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: ../../login/index.php');
  exit;
}

// ─── Fetch admin username ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();

// mark current page
$page = 'progress';

// ─── Load students for the dropdown ────────────────────────────────
$students = $conn
  ->query("SELECT id,name FROM students ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

// ─── Load remark templates ─────────────────────────────────────────
$templates = [];
$res = $conn->query("SELECT id,category_key,text FROM remark_templates");
while ($row = $res->fetch_assoc()) {
  $templates[$row['category_key']][] = $row;
}

// ─── Define grades & categories ───────────────────────────────────
$grades = [
  '1'=>'Needs Improvement',
  '2'=>'Average',
  '3'=>'Good',
  '4'=>'Very Good',
  '5'=>'Excellent',
];
$cats = [
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

// ─── Handle delete ─────────────────────────────────────────────────
if (isset($_GET['delete'])) {
  $stmt = $conn->prepare("DELETE FROM progress WHERE id=?");
  $stmt->bind_param('i', $_GET['delete']);
  $stmt->execute();
  header('Location: progress.php');
  exit;
}

// ─── Load one record if editing ────────────────────────────────────
$edit = null;
if (isset($_GET['id'])) {
  $stmt = $conn->prepare("SELECT * FROM progress WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $_GET['id']);
  $stmt->execute();
  $edit = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// ─── Handle form submission ────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $id         = (int)($_POST['id'] ?? 0);
  $student_id = (int)($_POST['student_id'] ?? 0);
  $month      = trim($_POST['month'] ?? '');
  if (!$student_id)    $errors[] = 'Select a student.';
  if (!preg_match('/^\d{4}-\d{2}$/',$month)) {
    $errors[] = 'Month must be in YYYY-MM format.';
  }

  // collect grades/remarks
  $data = [];
  foreach ($cats as $key => $label) {
    $g = $_POST[$key] ?? '';
    if (!isset($grades[$g])) {
      $errors[] = "Pick a grade for “{$label}”.";
    }
    // template vs custom
    $tid    = (int)($_POST[$key.'_template'] ?? 0);
    $custom = trim($_POST[$key.'_custom'] ?? '');
    if ($tid && isset($templates[$key])) {
      // ensure template exists
      $found = '';
      foreach ($templates[$key] as $t) {
        if ($t['id']==$tid) { $found = $t['text']; break; }
      }
      if ($found==='') {
        $errors[] = "Invalid template for “{$label}”.";
      }
      $r = $found;
    } else {
      if ($custom==='') {
        $errors[] = "Enter a remark for “{$label}”.";
      }
      $r = $custom;
    }

    $data[$key] = $g;
    $data[$remarkCols[$key]] = $r;
  }

  if (!$errors) {
    // build SQL dynamically
    if ($id>0) {
      // UPDATE
      $sql   = "UPDATE progress SET student_id=?,month=?";
      $types = 'is';
      $vals  = [$student_id,$month];
      foreach ($cats as $key => $_) {
        $sql   .= ", {$key}=?, {$remarkCols[$key]}=?";
        $types .= 'is';
        $vals[] = $data[$key];
        $vals[] = $data[$remarkCols[$key]];
      }
      $sql   .= " WHERE id=?";
      $types .= 'i';
      $vals[]  = $id;
      $stmt   = $conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $stmt->close();
    } else {
      // INSERT
      $cols  = 'student_id,month';
      $ph    = '?,?';
      $types = 'is';
      $vals  = [$student_id,$month];
      foreach ($cats as $key => $_) {
        $cols  .= ",{$key},{$remarkCols[$key]}";
        $ph    .= ",?,?";
        $types .= 'is';
        $vals[] = $data[$key];
        $vals[] = $data[$remarkCols[$key]];
      }
      $sql  = "INSERT INTO progress ({$cols}) VALUES ({$ph})";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param($types, ...$vals);
      $stmt->execute();
      $stmt->close();
    }

    header('Location: progress.php');
    exit;
  }
}

// ─── Fetch all existing entries ───────────────────────────────────
$all = $conn
  ->query("
    SELECT p.*, s.name
      FROM progress p
      JOIN students s ON s.id=p.student_id
     ORDER BY p.month DESC, s.name
  ")
  ->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel — Progress</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link 
   href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" 
   rel="stylesheet">
  <link 
   href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" 
   rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .topbar { background:#343a40; color:#fff; padding:.75rem 1.5rem; }
    .sidebar { background:#fff; min-height:100vh; border-right:1px solid #ddd; }
    .nav-link.active { background:#007bff; color:#fff; }
  </style>
</head>
<body>

      <!-- Main Content -->
      <main class="col-md-10 py-4">

        <h2 class="mb-4"><i class="bi bi-bar-chart-line"></i> Manage Progress</h2>

        <?php if($errors): ?>
          <div class="alert alert-danger">
            <ul class="mb-0">
              <?php foreach($errors as $e): ?>
                <li><?=htmlspecialchars($e)?></li>
              <?php endforeach;?>
            </ul>
          </div>
        <?php endif;?>

        <form method="post" class="row g-3 mb-5">
          <input type="hidden" name="id" value="<?= $edit['id'] ?? '' ?>">

          <div class="col-md-4">
            <label class="form-label">Student</label>
            <select name="student_id" class="form-select" required>
              <option value="">— select —</option>
              <?php foreach($students as $s): ?>
                <option 
                 value="<?=$s['id']?>"
                 <?= ($s['id']==($edit['student_id']??''))?'selected':''?>>
                  <?=htmlspecialchars($s['name'])?>
                </option>
              <?php endforeach;?>
            </select>
          </div>

          <div class="col-md-2">
            <label class="form-label">Month</label>
            <input 
              name="month" 
              type="month" 
              class="form-control"
              value="<?=htmlspecialchars($edit['month']??'')?>"
              required>
          </div>

          <?php foreach($cats as $key=>$label):
            $gval = $edit[$key] ?? '';
            $rval = $edit[$remarkCols[$key]] ?? '';
          ?>
            <div class="col-md-2">
              <label class="form-label"><?=$label?> Grade</label>
              <select name="<?=$key?>" class="form-select" required>
                <option value="">—</option>
                <?php foreach($grades as $gv=>$gt): ?>
                  <option 
                    value="<?=$gv?>" 
                    <?= ($gv==$gval)?'selected':''?>>
                    <?=$gt?>
                  </option>
                <?php endforeach;?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label"><?=$label?> Remark</label>
              <div class="input-group">
                <select 
                  name="<?=$key?>_template" 
                  class="form-select">
                  <option value="">— template —</option>
                  <?php if(!empty($templates[$key])): ?>
                    <?php foreach($templates[$key] as $t): ?>
                      <option 
                        value="<?=$t['id']?>"
                        <?= ($t['text']==$rval)?'selected':''?>>
                        <?=htmlspecialchars($t['text'])?>
                      </option>
                    <?php endforeach;?>
                  <?php endif;?>
                </select>
                <input
                  name="<?=$key?>_custom"
                  class="form-control"
                  placeholder="…or custom…"
                  value="<?=htmlspecialchars($rval)?>">
              </div>
            </div>
          <?php endforeach;?>

          <div class="col-12 text-end">
            <button class="btn btn-primary">
              <?= $edit ? 'Save Changes':'Add Progress'?>
            </button>
            <?php if($edit): ?>
              <a href="progress.php" class="btn btn-secondary ms-2">Cancel</a>
            <?php endif;?>
          </div>
        </form>

        <h4>Existing Records</h4>
        <div class="table-responsive">
          <table class="table table-striped align-middle">
            <thead class="table-dark">
              <tr>
                <th>#</th>
                <th>Student</th>
                <th>Month</th>
                <?php foreach($cats as $key=>$label): ?>
                  <th><?=htmlspecialchars($label)?></th>
                <?php endforeach;?>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if(empty($all)): ?>
                <tr>
                  <td colspan="<?= 4 + count($cats)?>" class="text-center">
                    No records found.
                  </td>
                </tr>
              <?php else: foreach($all as $r): ?>
                <tr>
                  <td><?=$r['id']?></td>
                  <td><?=htmlspecialchars($r['name'])?></td>
                  <td><?=htmlspecialchars($r['month'])?></td>
                  <?php foreach($cats as $key=>$label): ?>
                    <?php 
                      $gv = $r[$key] ?? '';
                      $txt= $grades[$gv] ?? '—';
                    ?>
                    <td><?=htmlspecialchars($txt)?></td>
                  <?php endforeach;?>
                  <td>
                    <a href="progress.php?id=<?=$r['id']?>"
                       class="btn btn-sm btn-outline-primary">Edit</a>
                    <a href="progress.php?delete=<?=$r['id']?>"
                       class="btn btn-sm btn-outline-danger"
                       onclick="return confirm('Delete this?')">
                      Delete
                    </a>
                  </td>
                </tr>
              <?php endforeach; endif;?>
            </tbody>
          </table>
        </div>

      </main>
    </div>
  </div>
</body>
</html>
