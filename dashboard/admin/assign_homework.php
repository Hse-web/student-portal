<?php
// File: dashboard/admin/assign_homework.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';   // create_notification(), log_audit()
require_once __DIR__ . '/../../config/db.php';

date_default_timezone_set('Asia/Kolkata');

// ─── 1) CSRF & rate-limit ─────────────────────────────────────
$csrf = generate_csrf_token();
if (!isset($_SESSION['assign_history'])) {
    $_SESSION['assign_history'] = [];
}
$_SESSION['assign_history'] = array_filter(
    $_SESSION['assign_history'],
    fn($ts) => $ts > time() - 600
);

$errors = [];
$success = '';

// ─── 2) Load Centres & their Art-Groups ───────────────────────
$centres = $conn
    ->query("SELECT id,name FROM centres ORDER BY name")
    ->fetch_all(MYSQLI_ASSOC);

$centreGroups = [];
$res = $conn->query("
    SELECT 
      s.centre_id,
      ag.id           AS art_group_id,
      ag.label
    FROM students s
    JOIN student_promotions sp
      ON sp.student_id   = s.id
     AND sp.is_applied   = 1
    JOIN art_groups ag
      ON ag.id = sp.art_group_id
    GROUP BY s.centre_id, ag.id, ag.label
    ORDER BY s.centre_id, ag.sort_order
");
while ($r = $res->fetch_assoc()) {
    $centreGroups[$r['centre_id']][] = [
        'id'    => (int)$r['art_group_id'],
        'label' => $r['label'],
    ];
}
$res->free();

// ─── 3) Handle Delete Batch ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete') {
  if (!verify_csrf_token($_POST['csrf_token']??'')) {
    $errors[] = 'Session expired—reload and try again.';
  } else {
    $aid = (int)($_POST['assignment_id']??0);
    // record for audit
    $before = $conn
      ->prepare("SELECT * FROM homework_assigned WHERE id=?")
      ->bind_param('i',$aid)
      ->execute()
      ->get_result()
      ->fetch_assoc();
    $conn->prepare("DELETE FROM homework_assigned WHERE id=?")
         ->bind_param('i',$aid)
         ->execute();
    if (function_exists('log_audit')) {
      log_audit($conn, $_SESSION['user_id'],'DELETE','homework_assigned',$aid,$before);
    }
    $success = 'Assignment batch deleted.';
  }
}

// ─── 4) Handle Assign New Homework ─────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='assign') {
  if (!verify_csrf_token($_POST['csrf_token']??'')) {
    $errors[] = 'Session expired—try again.';
  }
  if (count($_SESSION['assign_history']) >= 5) {
    $errors[] = 'Too many assignments in the last 10 minutes.';
  }

  // pull only from POST, never from GET
  $centre_id     = (int)($_POST['centre_id']    ?? 0);
  $group_id      = (int)($_POST['group_id']     ?? 0);
  $title         = trim($_POST['title']         ?? '');
  $description   = trim($_POST['description']   ?? '');
  $date_assigned = trim($_POST['date_assigned'] ?? '');

  if ($centre_id<1)     $errors[] = 'Please select a centre.';
  if ($group_id<1)      $errors[] = 'Please select a stage.';
  if ($title==='')      $errors[] = 'Title cannot be blank.';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date_assigned)) {
    $errors[] = 'Please pick a valid date.';
  }

  // optional upload
  $uploadPath = '';
  if (!empty($_FILES['attachment']['tmp_name'])) {
    $f = $_FILES['attachment'];
    if ($f['error']===UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
      if (in_array($ext,['pdf','jpg','jpeg','png'])) {
        $dst = 'uploads/homework/'.time().'_'.basename($f['name']);
        if (move_uploaded_file($f['tmp_name'],__DIR__.'/../../'.$dst)) {
          $uploadPath = $dst;
        } else {
          $errors[] = 'Failed to save attachment.';
        }
      } else {
        $errors[] = 'Unsupported file type.';
      }
    } else {
      $errors[] = 'Upload error.';
    }
  }

  if (empty($errors)) {
    // 1) find all eligible students
    $stmt = $conn->prepare("
      SELECT s.id
        FROM students s
        JOIN student_promotions sp
          ON sp.student_id   = s.id
         AND sp.art_group_id = ?
       WHERE s.centre_id     = ?
         AND sp.effective_date <= ?
    ");
    $stmt->bind_param('iis',$group_id,$centre_id,$date_assigned);
    $stmt->execute();
    $allIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id');
    $stmt->close();

    // 2) subtract those already assigned today
    $stmt = $conn->prepare("
      SELECT student_id
        FROM homework_assigned
       WHERE centre_id=? AND art_group_id=? AND date_assigned=?
    ");
    $stmt->bind_param('iis',$centre_id,$group_id,$date_assigned);
    $stmt->execute();
    $already = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'student_id');
    $stmt->close();

    $newIds = array_diff($allIds,$already);

    // 3) bulk insert
    if ($newIds) {
      $ins = $conn->prepare("
        INSERT INTO homework_assigned
          (student_id,centre_id,art_group_id,date_assigned,title,description,file_path)
        VALUES(?,?,?,?,?,?,?)
      ");
      foreach ($newIds as $sid) {
        $ins->bind_param(
          'iiissss',
          $sid,
          $centre_id,
          $group_id,
          $date_assigned,
          $title,
          $description,
          $uploadPath
        );
        $ins->execute();
      }
      $ins->close();
    }

    $_SESSION['assign_history'][]=time();
    $countNew=count($newIds);
    $success="Assigned to {$countNew} new student(s).";

    if ($countNew && function_exists('create_notification')) {
      create_notification(
        $conn,
        $newIds,
        'New Homework',
        "You have new homework “{$title}” dated {$date_assigned}."
      );
    }
  }
}

// ─── 5) Fetch Summary of Batches ────────────────────────────────
$where = [];
if (isset($_GET['centre']) && $_GET['centre']!=='') {
  $cid = (int)$_GET['centre'];
  $where[] = "ha.centre_id={$cid}";
}
if (isset($_GET['group']) && $_GET['group']!=='') {
  $gid = (int)$_GET['group'];
  $where[] = "ha.art_group_id={$gid}";
}
$whereSQL = $where ? 'WHERE '.implode(' AND ',$where) : '';

$assignments = [];
$q = "
  SELECT
    ha.date_assigned AS date,
    ha.title,
    ha.description,
    ha.file_path,
    COUNT(*)         AS cnt,
    MIN(ha.id)       AS ex_id
  FROM homework_assigned ha
  {$whereSQL}
  GROUP BY ha.date_assigned,ha.title,ha.description,ha.file_path
  ORDER BY ha.date_assigned DESC
";
$res = $conn->query($q);
while ($r=$res->fetch_assoc()) {
  $assignments[]=$r;
}
$res->free();

// ─── Prepare POST-sticky vars ───────────────────────────────────
$postCentre = $_POST['centre_id']   ?? '';
$postGroup  = $_POST['group_id']    ?? '';
$postDate   = $_POST['date_assigned'] ?? '';
$postTitle  = $_POST['title']         ?? '';
$postDesc   = $_POST['description']   ?? '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Assign Homework</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"/>
  <style>
    /* hide collapsed panels */
    #hwAccordion .accordion-collapse {
      display:none!important;
      visibility:hidden!important;
    }
    #hwAccordion .accordion-collapse.show {
      display:block!important;
      visibility:visible!important;
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-5">

    <h1 class="mb-4"><i class="bi bi-pencil-square text-primary"></i> Assign Homework</h1>

    <?php if($success): ?>
      <div class="alert alert-success"><?=htmlspecialchars($success)?></div>
    <?php endif; ?>
    <?php if($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0">
        <?php foreach($errors as $e): ?>
          <li><?=htmlspecialchars($e)?></li>
        <?php endforeach; ?>
      </ul></div>
    <?php endif; ?>

    <!-- Assign Form -->
    <div class="card mb-4">
      <div class="card-body">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?=$csrf?>">
          <input type="hidden" name="action"     value="assign">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Centre</label>
              <select id="centre" name="centre_id" class="form-select" required>
                <option value="">— Select Centre —</option>
                <?php foreach($centres as $c): ?>
                  <option value="<?=$c['id']?>"
                    <?= $postCentre==$c['id']?'selected':''?>>
                    <?=htmlspecialchars($c['name'])?>
                  </option>
                <?php endforeach;?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Stage</label>
              <select id="group" name="group_id" class="form-select" required>
                <option value="">— Select Stage —</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Assigned Date</label>
              <input type="date"
                     name="date_assigned"
                     class="form-control"
                     value="<?=htmlspecialchars($postDate)?>"
                     required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Title</label>
              <input type="text"
                     name="title"
                     class="form-control"
                     value="<?=htmlspecialchars($postTitle)?>"
                     required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Attachment</label>
              <input type="file"
                     name="attachment"
                     class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description"
                        rows="3"
                        class="form-control"
                        required><?=htmlspecialchars($postDesc)?></textarea>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-primary">Assign Homework</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Existing Batches Accordion -->
    <div class="accordion" id="hwAccordion">
      <?php foreach($assignments as $i=>$a):
        $uid = "hw{$i}";
      ?>
        <div class="accordion-item mb-2">
          <h2 class="accordion-header" id="heading-<?=$uid?>">
            <button class="accordion-button collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#collapse-<?=$uid?>"
                    aria-expanded="false">
              <?=htmlspecialchars($a['date'])?> — <?=htmlspecialchars($a['title'])?>
              <span class="badge bg-info ms-auto"><?= (int)$a['cnt']?> students</span>
            </button>
          </h2>
          <div id="collapse-<?=$uid?>"
               class="accordion-collapse collapse"
               aria-labelledby="heading-<?=$uid?>"
               data-bs-parent="#hwAccordion">
            <div class="accordion-body d-flex justify-content-between">
              <div>
                <?= nl2br(htmlspecialchars($a['description'])) ?>
                <?php if($a['file_path']): ?>
                  <div class="mt-2">
                    <a href="<?=htmlspecialchars($a['file_path'])?>"
                       class="btn btn-sm btn-outline-secondary">
                      <i class="bi bi-download"></i> Download
                    </a>
                  </div>
                <?php endif; ?>
              </div>
              <form method="post" onsubmit="return confirm('Delete this batch?')">
                <input type="hidden" name="csrf_token"    value="<?=$csrf?>">
                <input type="hidden" name="action"        value="delete">
                <input type="hidden" name="assignment_id" value="<?=$a['ex_id']?>">
                <button type="submit" class="btn btn-danger">Delete</button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if(empty($assignments)): ?>
        <div class="text-center text-muted py-4">No assignments yet.</div>
      <?php endif; ?>
    </div>

  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // drive the Stage dropdown off Centre
    const centreGroups = <?= json_encode($centreGroups) ?>;
    const elC = document.getElementById('centre');
    const elG = document.getElementById('group');

    function reloadStages() {
      elG.innerHTML = '<option value="">— Select Stage —</option>';
      (centreGroups[elC.value]||[]).forEach(g=>{
        const o = document.createElement('option');
        o.value = g.id; o.textContent = g.label;
        elG.append(o);
      });
    }

    elC.addEventListener('change', reloadStages);
    window.addEventListener('DOMContentLoaded', ()=>{
      if ('<?=$postCentre?>') {
        elC.value = '<?=$postCentre?>';
        reloadStages();
        if ('<?=$postGroup?>') {
          elG.value = '<?=$postGroup?>';
        }
      }
    });
  </script>
</body>
</html>
