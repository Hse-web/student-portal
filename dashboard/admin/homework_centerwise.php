<?php
// File: dashboard/admin/homework_centerwise.php

require_once __DIR__.'/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__.'/../helpers/functions.php';   // set_flash(), get_flash(), verify_csrf_token()
require_once __DIR__.'/../../config/db.php';

// ─── Handle Save ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_feedback') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    set_flash('Session expired; please try again.', 'danger');
  } else {
    // Persist feedback + stars + shown_in_class
    foreach ($_POST['students'] as $sid => $hws) {
      foreach ($hws as $hwid => $_) {
        $shown = isset($_POST['shown'][$sid][$hwid]) ? 1 : 0;
        $star  = (int)($_POST['stars'][$sid][$hwid] ?? 0);
        $fb    = trim($_POST['feedback'][$sid][$hwid] ?? '');

        $stmt = $conn->prepare("
          INSERT INTO homework_submissions
            (assignment_id, student_id, shown_in_class, star_given, feedback, submitted_at)
          VALUES (?, ?, ?, ?, ?, NOW())
          ON DUPLICATE KEY UPDATE
            shown_in_class=VALUES(shown_in_class),
            star_given    =VALUES(star_given),
            feedback      =VALUES(feedback)
        ");
        $stmt->bind_param('iiiis', $hwid, $sid, $shown, $star, $fb);
        $stmt->execute();
        $stmt->close();
      }
    }
    set_flash('Feedback & stars saved.', 'success');
  }

  // Redirect back with the same filters
  $qs = http_build_query([
    'page'   => 'homework_centerwise',
    'centre' => $_GET['centre'] ?? '',
    'group'  => $_GET['group']  ?? '',
    'month'  => $_GET['month']  ?? '',
  ]);
  header("Location:?{$qs}");
  exit;
}

// ─── Build WHERE clause from filters ───────────────────────────────────
$centre = (int)($_GET['centre'] ?? 0);
$group  = trim($_GET['group'] ?? '');
$month  = $_GET['month'] ?? date('Y-m');

$where = 'WHERE 1=1';
$types = '';
$params = [];
if ($centre) {
  $where .= ' AND s.centre_id=?';
  $types .= 'i';
  $params[] = $centre;
}
if ($group !== '') {
  $where .= ' AND s.group_name=?';
  $types .= 's';
  $params[] = $group;
}
if ($month !== '') {
  $where .= " AND DATE_FORMAT(ha.date_assigned,'%Y-%m')=?";
  $types .= 's';
  $params[] = $month;
}

// ─── Fetch everything ───────────────────────────────────────────────────
$sql = "
 SELECT 
   s.id   AS student_id,
   s.name AS student,
   ha.id  AS hw_id,
   ha.title,
   ha.date_assigned,
   hs.file_path   AS submission,
   hs.shown_in_class,
   hs.star_given,
   hs.feedback
 FROM students s
 JOIN centres c ON c.id = s.centre_id
 LEFT JOIN homework_assigned ha
   ON ha.student_id = s.id
 LEFT JOIN homework_submissions hs
   ON hs.assignment_id = ha.id
  AND hs.student_id    = s.id
 {$where}
 ORDER BY s.name, ha.date_assigned DESC
";
$stmt = $conn->prepare($sql);
if ($types) {
  $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Group rows by student ─────────────────────────────────────────────
$byStudent = [];
foreach ($data as $row) {
  if (!$row['hw_id']) {
    // no assignment for this student
    continue;
  }
  $sid = $row['student_id'];
  $byStudent[$sid]['name']       = $row['student'];
  $byStudent[$sid]['homework'][] = $row;
}

// ─── Fetch filter dropdowns & CSRF ─────────────────────────────────────
$centresList = $conn->query("SELECT id,name FROM centres ORDER BY name")
                    ->fetch_all(MYSQLI_ASSOC);
$groupsList  = $conn->query("SELECT DISTINCT group_name FROM students ORDER BY group_name")
                    ->fetch_all(MYSQLI_NUM);
$flash       = get_flash();
$csrf        = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Homework Submissions</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body class="bg-gray-50 text-gray-800">
  <div class="max-w-7xl mx-auto p-6">

    <?php if($flash): ?>
      <div class="mb-6 p-4 rounded border
                  <?= $flash['type']==='danger'
                      ?'border-red-400 bg-red-100'
                      :'border-green-400 bg-green-100' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <h1 class="text-3xl font-bold mb-6">📝 Homework Submissions</h1>

    <!-- Filters -->
    <form method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-8">
      <input type="hidden" name="page" value="homework_centerwise">
      <select name="centre" class="p-2 border rounded">
        <option value="">All Centres</option>
        <?php foreach($centresList as $c): ?>
          <option value="<?=$c['id']?>" <?= $centre === $c['id'] ? 'selected' : ''?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="group" class="p-2 border rounded">
        <option value="">All Groups</option>
        <?php foreach($groupsList as [$g]): ?>
          <option value="<?=htmlspecialchars($g)?>" <?= $group === $g ? 'selected' : ''?>>
            <?= htmlspecialchars($g) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="month"
             name="month"
             value="<?=htmlspecialchars($month)?>"
             class="p-2 border rounded"/>

      <button class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">
        Filter
      </button>
    </form>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?=$csrf?>">
      <input type="hidden" name="action" value="save_feedback">

      <!-- Accordion list -->
      <div class="space-y-4">
      <?php $first=true; foreach($byStudent as $sid=>$st): ?>
        <?php if(empty($st['homework'])) continue; ?>
        <div class="bg-white border rounded shadow">

          <!-- Header -->
          <button type="button"
                  class="w-full flex justify-between px-4 py-3 font-medium text-left"
                  data-toggle="panel-<?=$sid?>">
            <span><?=htmlspecialchars($st['name'])?></span>
            <span class="bg-gray-200 text-gray-600 text-sm rounded-full px-2">
              <?=count($st['homework'])?> hw
            </span>
          </button>

          <!-- Body -->
          <div id="panel-<?=$sid?>"
               class="px-4 pb-4 <?= $first?'block':'hidden' ?>">
            <table class="w-full mt-2">
              <thead class="bg-gray-100 text-gray-700">
                <tr>
                  <th class="p-2 text-left">Assigned</th>
                  <th class="p-2 text-left">Title</th>
                  <th class="p-2 text-center">Submission</th>
                  <th class="p-2 text-center">Shown</th>
                  <th class="p-2 text-center">Stars</th>
                  <th class="p-2 text-left">Feedback</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($st['homework'] as $hw): ?>
                <?php
                  // ensure feedback is never null
                  $rawFb = $hw['feedback'] ?? '';
                ?>
                <tr class="border-b last:border-none">
                  <td class="p-2"><?=htmlspecialchars($hw['date_assigned'])?></td>
                  <td class="p-2"><?=htmlspecialchars($hw['title'])?></td>
                 <td class="text-center">
  <?php if (! empty($hw['submission'])): ?>
    <a href="<?= '/artovue/' . ltrim($hw['submission'], '/') ?>" target="_blank"
      class="text-blue-600 hover:underline">View File</a>
  <?php else: ?>
    <span class="text-red-500 font-bold">✘</span>
  <?php endif; ?>
</td>
                  <td class="p-2 text-center">
                    <input type="hidden" name="students[<?=$sid?>][<?=$hw['hw_id']?>]" value="1">
                    <input type="checkbox"
                           name="shown[<?=$sid?>][<?=$hw['hw_id']?>]"
                           class="h-5 w-5 text-blue-600"
                           <?=($hw['shown_in_class']||$hw['submission'])?'checked':''?>>
                  </td>
                  <td class="p-2 text-center">
                    <?php for($i=1;$i<=3;$i++): ?>
                      <button type="button"
                              class="star-btn inline-block mx-1 text-xl text-gray-300 hover:text-yellow-400"
                              data-sid="<?=$sid?>"
                              data-hid="<?=$hw['hw_id']?>"
                              data-star="<?=$i?>">
                        ★
                      </button>
                    <?php endfor; ?>
                    <input type="hidden"
                           name="stars[<?=$sid?>][<?=$hw['hw_id']?>]"
                           value="<?=$hw['star_given']?>">
                  </td>
                  <td class="p-2">
                    <textarea name="feedback[<?=$sid?>][<?=$hw['hw_id']?>]"
                              rows="1"
                              class="w-full p-1 border rounded resize-none"><?=htmlspecialchars($rawFb)?></textarea>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php $first=false; endforeach; ?>
      </div>

      <div class="mt-6 text-right">
        <button class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
          Save All
        </button>
      </div>
    </form>
  </div>

  <script>
    // Accordion
    document.querySelectorAll('[data-toggle]').forEach(btn=>{
      btn.addEventListener('click',()=>{
        const id = btn.getAttribute('data-toggle');
        document.querySelectorAll('[id^="panel-"]').forEach(p=>{
          if(p.id===id) p.classList.toggle('hidden');
          else         p.classList.add('hidden');
        });
      });
    });

    // Star‐rating
    document.querySelectorAll('.star-btn').forEach(b=>{
      b.addEventListener('click',()=>{
        const sid = b.dataset.sid,
              hid = b.dataset.hid,
              val = +b.dataset.star;
        document.querySelector(`input[name="stars[${sid}][${hid}]"]`)
                .value = val;
        document.querySelectorAll(`.star-btn[data-sid="${sid}"][data-hid="${hid}"]`)
                .forEach(x=> {
                  if (+x.dataset.star <= val) {
                    x.classList.replace('text-gray-300','text-yellow-400');
                  } else {
                    x.classList.replace('text-yellow-400','text-gray-300');
                  }
                });
      });
    });
  </script>
</body>
</html>
