<?php
// File: dashboard/admin/assign_homework.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── 1) CSRF + simple rate‐limit ───────────────────────────────────
$csrf = generate_csrf_token();
if (! isset($_SESSION['assign_history'])) {
  $_SESSION['assign_history'] = [];
}
$_SESSION['assign_history'] = array_filter(
  $_SESSION['assign_history'],
  fn($ts) => $ts > time() - 600
);

$errors  = [];
$success = '';

// ─── 2) Load Centres & Stage mappings from your art_groups… ──────
$centres = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$centreGroups = [];
$res = $conn->query("
  SELECT 
    s.centre_id,
    ag.id       AS art_group_id,
    ag.label    AS label
  FROM students s
  JOIN student_promotions sp
    ON sp.student_id = s.id
   AND sp.is_applied = 1
  JOIN art_groups ag
    ON ag.id = sp.art_group_id
  GROUP BY s.centre_id, art_group_id, ag.label
  ORDER BY s.centre_id, ag.sort_order
");
while ($r = $res->fetch_assoc()) {
  $centreGroups[$r['centre_id']][] = [
    'id'    => (int)$r['art_group_id'],
    'label' => $r['label'],
  ];
}
$res->free();

// ─── 3) Handle “delete” ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete') {
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Session expired—reload and try again.';
  } else {
    $aid = (int)($_POST['assignment_id'] ?? 0);
    // fetch “before” for audit
    $stmt = $conn->prepare("
      SELECT student_id,title,description,file_path
        FROM homework_assigned
       WHERE id = ?
    ");
    $stmt->bind_param('i',$aid);
    $stmt->execute();
    $before = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // delete
    $stmt = $conn->prepare("DELETE FROM homework_assigned WHERE id = ?");
    $stmt->bind_param('i',$aid);
    $stmt->execute();
    if ($stmt->affected_rows) {
      if (function_exists('log_audit')) {
        log_audit(
          $conn,
          $_SESSION['user_id'],
          'DELETE',
          'homework_assigned',
          $aid,
          $before ?: []
        );
      }
      $success = 'Assignment batch deleted.';
    }
    $stmt->close();
  }
}

// ─── 4) Handle “assign” ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='assign') {
  // CSRF + rate-limit check
  if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
    $errors[] = 'Session expired—reload and try again.';
  }
  if (count($_SESSION['assign_history']) >= 5) {
    $errors[] = 'Too many assignments in the last 10 minutes.';
  }

  // gather + validate
  $centre_id   = (int)($_POST['centre_id']   ?? 0);
  $group_id    = (int)($_POST['group_id']    ?? 0);
  $title       = trim($_POST['title']        ?? '');
  $description = trim($_POST['description']  ?? '');
  $file_path   = null;

  if ($centre_id < 1)    $errors[] = 'Please select a centre.';
  if ($group_id  < 1)    $errors[] = 'Please select a stage.';
  if ($title === '')     $errors[] = 'Title cannot be blank.';
  if ($description==='') $errors[] = 'Description cannot be blank.';

  // optional upload
  if (!empty($_FILES['attachment']['name'])) {
    $f = $_FILES['attachment'];
    $allowed = ['application/pdf','image/jpeg','image/png','image/jpg'];
    if ($f['error']===0 && in_array($f['type'],$allowed,true)) {
      $dir = __DIR__.'/../../uploads/homework_attachments/';
      if (!is_dir($dir)) mkdir($dir,0755,true);
      $fn = bin2hex(random_bytes(8)).'_'.basename($f['name']);
      if (move_uploaded_file($f['tmp_name'],"$dir$fn")) {
        $file_path = "uploads/homework_attachments/$fn";
      } else {
        $errors[] = 'Attachment upload failed.';
      }
    } else {
      $errors[] = 'Attachment must be PDF/JPG/PNG.';
    }
  }

  // if all clear, insert one record per student in that centre+stage
  if (empty($errors)) {
    $stmt = $conn->prepare("
      SELECT s.id
        FROM students s
        JOIN student_promotions sp
          ON sp.student_id = s.id
         AND sp.art_group_id = ?
       WHERE s.centre_id = ?
    ");
    $stmt->bind_param('ii',$group_id,$centre_id);
    $stmt->execute();
    $ids = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'id');
    $stmt->close();

    if (empty($ids)) {
      $errors[] = 'No students found in that stage.';
    } else {
      $ins = $conn->prepare("
        INSERT INTO homework_assigned
          (student_id,date_assigned,title,description,file_path)
        VALUES (?,NOW(),?,?,?)
      ");
      foreach ($ids as $sid) {
        $ins->bind_param('isss',$sid,$title,$description,$file_path);
        $ins->execute();
        if (function_exists('log_audit')) {
          log_audit(
            $conn,
            $_SESSION['user_id'],
            'INSERT',
            'homework_assigned',
            $ins->insert_id,
            compact('sid','title','description','file_path')
          );
        }
      }
      $ins->close();
      $_SESSION['assign_history'][] = time();
      $success = 'Assigned to '.count($ids).' student(s).';
    }
  }
}

// ─── 5) Fetch summary for display ───────────────────────────────────
$assignments = [];
$q = "
  SELECT
    DATE_FORMAT(ha.date_assigned,'%d %b %Y') AS date,
    c.name           AS centre,
    s.group_name     AS stage,
    ha.title,
    COUNT(*)         AS cnt,
    MIN(ha.id)       AS ex_id
  FROM homework_assigned ha
  JOIN students s   ON s.id = ha.student_id
  JOIN centres  c   ON c.id = s.centre_id
  GROUP BY ha.date_assigned,c.name,s.group_name,ha.title
  ORDER BY ha.date_assigned DESC
";
$res = $conn->query($q);
while ($r = $res->fetch_assoc()) {
  $assignments[] = $r;
}
$res->free();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Assign Homework – Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>
</head>
<body class="bg-gray-100 min-h-screen">

  <div class="container mx-auto px-4 py-6">

    <h1 class="text-2xl font-bold mb-6 flex items-center space-x-2">
      <i class="bi bi-journal-text-fill text-purple-600 text-3xl"></i>
      <span>Assign Homework</span>
    </h1>

    <?php if ($success): ?>
      <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-lg">
        <?= htmlspecialchars($success, ENT_QUOTES) ?>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-lg">
        <ul class="list-disc pl-5 space-y-1">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e, ENT_QUOTES) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ─── Form Panel ──────────────────────────────────────────────── -->
    <div class="bg-white p-6 rounded-2xl shadow-md">
      <form method="post" enctype="multipart/form-data" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action"     value="assign">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <!-- Centre -->
          <div>
            <label class="block text-gray-700 mb-1">Centre</label>
            <select id="centre"
                    name="centre_id"
                    required
                    class="w-full border-gray-300 rounded-lg p-2 focus:ring-purple-500 focus:border-purple-500">
              <option value="">— Select Centre —</option>
              <?php foreach ($centres as $c): ?>
                <option value="<?= (int)$c['id'] ?>"
                  <?= ((int)($_POST['centre_id']??0) === (int)$c['id'])?'selected':'' ?>>
                  <?= htmlspecialchars($c['name'], ENT_QUOTES) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Stage -->
          <div>
            <label class="block text-gray-700 mb-1">Stage</label>
            <select id="group"
                    name="group_id"
                    required
                    class="w-full border-gray-300 rounded-lg p-2 focus:ring-purple-500 focus:border-purple-500">
              <option value="">— Select Stage —</option>
              <!-- JS will populate here -->
            </select>
          </div>
        </div>

        <div>
          <label class="block text-gray-700 mb-1">Title</label>
          <input type="text"
                 name="title"
                 required
                 value="<?= htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES) ?>"
                 class="w-full border-gray-300 rounded-lg p-2 focus:ring-purple-500 focus:border-purple-500">
        </div>

        <div>
          <label class="block text-gray-700 mb-1">Description</label>
          <textarea name="description"
                    rows="4"
                    required
                    class="w-full border-gray-300 rounded-lg p-2 focus:ring-purple-500 focus:border-purple-500"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES) ?></textarea>
        </div>

        <div>
          <label class="block text-gray-700 mb-1">Attachment (PDF/JPG/PNG)</label>
          <input type="file"
                 name="attachment"
                 accept=".pdf,.jpeg,.jpg,.png"
                 class="w-full border-gray-300 rounded-lg p-2">
        </div>

        <button type="submit"
                class="bg-purple-600 hover:bg-purple-700 text-white py-3 px-6 rounded-lg font-medium w-full md:w-auto transition">
          Assign to Students
        </button>
      </form>
    </div>

    <!-- ─── Existing Assignments ────────────────────────────────────── -->
    <div class="mt-8 overflow-x-auto bg-white rounded-2xl shadow-md">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead class="bg-gray-50 text-gray-700 font-medium">
          <tr>
            <th class="p-3">Date</th>
            <th class="p-3">Centre</th>
            <th class="p-3">Stage</th>
            <th class="p-3">Title</th>
            <th class="p-3 text-center"># Students</th>
            <th class="p-3 text-center">Delete</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($assignments)): ?>
            <tr>
              <td colspan="6" class="p-4 text-center text-gray-500">
                No assignments yet.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($assignments as $a): ?>
              <tr class="hover:bg-gray-50">
                <td class="p-3"><?= htmlspecialchars($a['date'], ENT_QUOTES) ?></td>
                <td class="p-3"><?= htmlspecialchars($a['centre'], ENT_QUOTES) ?></td>
                <td class="p-3"><?= htmlspecialchars($a['stage'], ENT_QUOTES) ?></td>
                <td class="p-3"><?= htmlspecialchars($a['title'], ENT_QUOTES) ?></td>
                <td class="p-3 text-center"><?= (int)$a['cnt'] ?></td>
                <td class="p-3 text-center">
                  <form method="post"
                        onsubmit="return confirm('Delete this batch?')"
                        class="inline">
                    <input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
                    <input type="hidden" name="action"        value="delete">
                    <input type="hidden" name="assignment_id" value="<?= (int)$a['ex_id'] ?>">
                    <button class="text-red-600 hover:text-red-800">
                      <i class="bi bi-trash-fill"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─── Centre→Stage JS ──────────────────────────────────────────── -->
  <script>
    const centreGroups = <?= json_encode($centreGroups, JSON_HEX_TAG) ?>;
    const elCentre = document.getElementById('centre');
    const elGroup  = document.getElementById('group');

    function refreshStages(){
      // reset
      elGroup.innerHTML = '<option value="">— Select Stage —</option>';
      (centreGroups[elCentre.value] || []).forEach(stage => {
        const o = document.createElement('option');
        o.value       = stage.id;
        o.textContent = stage.label;
        elGroup.appendChild(o);
      });
    }

    elCentre.addEventListener('change', refreshStages);

    // if user posted & we had an error, prefill
    window.addEventListener('DOMContentLoaded', () => {
      <?php if(!empty($_POST['centre_id'])): ?>
        elCentre.value = <?= json_encode((int)$_POST['centre_id']) ?>;
        refreshStages();
        <?php if(!empty($_POST['group_id'])): ?>
          elGroup.value = <?= json_encode((int)$_POST['group_id']) ?>;
        <?php endif; ?>
      <?php endif; ?>
    });
  </script>

</body>
</html>
