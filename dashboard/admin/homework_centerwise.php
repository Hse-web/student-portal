<?php
// -----------------------------------------------------------------------------
//  dashboard/admin/homework_centerwise.php   –   Teacher review of submissions
// -----------------------------------------------------------------------------

require_once __DIR__.'/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__.'/../helpers/functions.php';   // set_flash(), get_flash(), verify_csrf_token()
require_once __DIR__.'/../../config/db.php';

/* ───────────────────────────── 1. Save feedback ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_feedback') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        set_flash('Session expired; please try again.', 'danger');
    } else {
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
                      shown_in_class = VALUES(shown_in_class),
                      star_given     = VALUES(star_given),
                      feedback       = VALUES(feedback)
                ");
                $stmt->bind_param('iiiis', $hwid, $sid, $shown, $star, $fb);
                $stmt->execute();
                $stmt->close();
            }
        }
        set_flash('Feedback & stars saved.', 'success');
    }
    /* Preserve current filters on redirect */
    header('Location:?'.http_build_query([
        'page'   => 'homework_centerwise',
        'centre' => $_GET['centre'] ?? '',
        'group'  => $_GET['group']  ?? '',
        'month'  => $_GET['month']  ?? '',
    ]));
    exit;
}

/* ───────────────────────────── 2. Filters ──────────────────────────────── */
$centre = (int)($_GET['centre'] ?? 0);
$group  = trim($_GET['group'] ?? '');
$month  = $_GET['month'] ?? date('Y-m');

$where  = 'WHERE 1=1';
$types  = '';
$params = [];
if ($centre) { $where .= ' AND s.centre_id = ?';    $types .= 'i'; $params[] = $centre; }
if ($group)  { $where .= ' AND ag.label = ?';       $types .= 's'; $params[] = $group; }
$where .= " AND DATE_FORMAT(ha.date_assigned,'%Y-%m') = ?";
$types .= 's';  $params[] = $month;

/* ───────────────────────────── 3. Main query ─────────────────────────────
   • Latest submission row per (assignment, student) for feedback/stars.
   • latest_file sub-select gets most recent file (if any) even if row above
     has no file_path.
------------------------------------------------------------------------- */
$sql = "
SELECT
  s.id               AS student_id,
  s.name             AS student,
  ag.label           AS student_group,
  ha.id              AS hw_id,
  ha.title,
  ha.date_assigned,
  /* latest feedback/stars/shown row */
  hs.shown_in_class,
  hs.star_given,
  hs.feedback,
  /* choose file_path with COALESCE(latest_file , hs.file_path) */
  COALESCE(hs_file.latest_file, hs.file_path) AS submission
FROM students s
JOIN centres c            ON c.id = s.centre_id
JOIN student_promotions sp ON sp.student_id = s.id AND sp.is_applied = 1
JOIN art_groups ag         ON ag.id = sp.art_group_id
/* assignments for current group */
LEFT JOIN homework_assigned ha
  ON ha.student_id = s.id
 AND ha.art_group_id = sp.art_group_id
/* ---------------- latest feedback/stars row ---------------- */
LEFT JOIN (
   SELECT t.*
   FROM   homework_submissions t
   JOIN (
     SELECT assignment_id, student_id, MAX(submitted_at) AS max_sub
     FROM   homework_submissions
     GROUP  BY assignment_id, student_id
   ) x
     ON  x.assignment_id = t.assignment_id
    AND  x.student_id    = t.student_id
    AND  x.max_sub       = t.submitted_at
) AS hs ON hs.assignment_id = ha.id AND hs.student_id = s.id
/* ---------------- latest file (any row with file_path<>'') -------------- */
LEFT JOIN (
   SELECT y.assignment_id, y.student_id,
          y.file_path AS latest_file
   FROM   homework_submissions y
   JOIN (
       SELECT assignment_id, student_id, MAX(submitted_at) AS max_sub_with_file
       FROM   homework_submissions
       WHERE  file_path <> ''
       GROUP  BY assignment_id, student_id
   ) z
     ON z.assignment_id = y.assignment_id
    AND z.student_id    = y.student_id
    AND z.max_sub_with_file = y.submitted_at
) AS hs_file
  ON hs_file.assignment_id = ha.id
 AND hs_file.student_id    = s.id
$where
ORDER BY s.name, ha.date_assigned DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* ───────────────────────────── 4. Group by Student ───────────────────── */
$students = [];
foreach ($data as $row) {
    if (!$row['hw_id']) continue;              // no assignment found
    $sid = $row['student_id'];
    $students[$sid]['name']       = $row['student'];
    $students[$sid]['group']      = $row['student_group'];
    $students[$sid]['homeworks'][] = $row;
}

/* ───────────────────────────── UI helpers ────────────────────────────── */
$centresList = $conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$groupsList  = $conn->query("SELECT DISTINCT label FROM art_groups ORDER BY label")->fetch_all(MYSQLI_NUM);
$csrf  = generate_csrf_token();
$flash = get_flash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Homework Submissions</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
.star-btn{cursor:pointer;font-size:1.25rem;background:none;border:none;transition:.2s}
.star-btn:hover{transform:scale(1.2)}
.star-btn.active{color:#fbbf24;text-shadow:0 0 10px #fbbf2480}
.star-btn.inactive{color:#d1d5db}
.table-wrap{overflow-x:auto}
table{border-collapse:collapse;min-width:760px}
.readonly{background:#f3f4f6;color:#6b7280;cursor:not-allowed}
.readonly-star{pointer-events:none;opacity:.6}
</style>
</head>
<body>
<div class="max-w-7xl mx-auto p-6">
<?php if($flash):?>
<div class="mb-6 p-4 rounded border <?=$flash['type']==='danger'?'border-red-400 bg-red-100':'border-green-400 bg-green-100'?>">
<?=htmlspecialchars($flash['msg'])?></div>
<?php endif;?>

<h1 class="text-3xl font-bold mb-6">📝 Homework Submissions</h1>

<!-- Filters -->
<form method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-8">
<input type="hidden" name="page" value="homework_centerwise">
<select name="centre"  class="p-2 border rounded">
<option value="">All Centres</option>
<?php foreach($centresList as $c):?>
 <option value="<?=$c['id']?>" <?=$centre===$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
<?php endforeach;?>
</select>
<select name="group"   class="p-2 border rounded">
<option value="">All Groups</option>
<?php foreach($groupsList as [$g]):?>
 <option value="<?=htmlspecialchars($g)?>" <?=$group===$g?'selected':''?>><?=htmlspecialchars($g)?></option>
<?php endforeach;?>
</select>
<input type="month" name="month" value="<?=htmlspecialchars($month)?>" class="p-2 border rounded">
<button class="bg-blue-600 text-white p-2 rounded hover:bg-blue-700">Filter</button>
</form>

<form method="post">
<input type="hidden" name="csrf_token" value="<?=$csrf?>">
<input type="hidden" name="action" value="save_feedback">

<?php foreach($students as $sid=>$info):?>
<div style="margin-bottom:1.5em;border:1px solid #ccc;border-radius:5px;padding:1em;">
<button type="button" style="width:100%;text-align:left;font-weight:bold;font-size:1.1rem;margin-bottom:.5em"
        data-toggle="panel-<?=$sid?>"><?=htmlspecialchars($info['name'])?> (<?=htmlspecialchars($info['group'])?>) – <?=count($info['homeworks'])?> homework(s)</button>
<div id="panel-<?=$sid?>" style="display:none">
 <div class="table-wrap">
  <table>
   <thead><tr><th>Assigned</th><th>Title</th><th>Submission</th><th>Shown</th><th>Stars</th><th>Feedback</th></tr></thead>
   <tbody>
   <?php foreach($info['homeworks'] as $hw):
        $locked = ($hw['feedback']!='' || $hw['shown_in_class'] || $hw['star_given']>0);
   ?>
    <tr>
     <td><?=htmlspecialchars($hw['date_assigned'])?></td>
     <td><?=htmlspecialchars($hw['title'])?></td>
     <td style="text-align:center">
      <?php if($hw['submission']):?>
       <a href="/artovue/<?=htmlspecialchars(ltrim($hw['submission'],'/'))?>" target="_blank">View File</a>
      <?php else:?>
       <span style="color:#dc2626;font-weight:bold">&#10060;</span>
      <?php endif;?>
     </td>
     <td style="text-align:center">
       <input type="hidden" name="students[<?=$sid?>][<?=$hw['hw_id']?>]" value="1">
       <input type="checkbox"
              name="shown[<?=$sid?>][<?=$hw['hw_id']?>]"
              value="1"
              <?=$hw['shown_in_class']?'checked':''?>
              <?=$locked?'disabled':''?>>
       <?php if($locked && $hw['shown_in_class']):?>
         <input type="hidden" name="shown[<?=$sid?>][<?=$hw['hw_id']?>]" value="1">
       <?php endif;?>
     </td>
     <td style="text-align:center">
       <div class="<?=$locked?'readonly-star':''?>">
        <?php for($i=1;$i<=3;$i++):?>
         <button type="button"
                 class="star-btn <?= $i<=($hw['star_given']??0)?'active':'inactive'?>"
                 data-sid="<?=$sid?>" data-hid="<?=$hw['hw_id']?>" data-star="<?=$i?>" <?=$locked?'disabled':''?>>★</button>
        <?php endfor;?>
       </div>
       <input type="hidden" name="stars[<?=$sid?>][<?=$hw['hw_id']?>]" value="<?= $hw['star_given']??0 ?>">
     </td>
     <td>
       <textarea name="feedback[<?=$sid?>][<?=$hw['hw_id']?>]" rows="2" class="w-full p-1 border rounded resize-none <?=$locked?'readonly':''?>" <?=$locked?'readonly':''?>><?=htmlspecialchars($hw['feedback']??'')?></textarea>
     </td>
    </tr>
   <?php endforeach;?>
   </tbody>
  </table>
 </div>
</div>
</div>
<?php endforeach;?>

<div style="text-align:right;margin-top:1em;">
 <button style="padding:.5em 1em;background:#16a34a;color:#fff;border:none;border-radius:4px;cursor:pointer">Save All Feedback</button>
</div>
</form>

<script>
// accordion
document.querySelectorAll('[data-toggle]').forEach(btn=>{
 btn.addEventListener('click',()=>{
   const id=btn.getAttribute('data-toggle');
   const panel=document.getElementById(id);
   panel.style.display=panel.style.display==='block'?'none':'block';
 });
});
// stars
document.querySelectorAll('.star-btn:not([disabled])').forEach(btn=>{
 btn.addEventListener('click',()=>{
   const sid=btn.dataset.sid,hid=btn.dataset.hid,val=parseInt(btn.dataset.star,10);
   document.querySelector(`input[name="stars[${sid}][${hid}]"]`).value=val;
   document.querySelectorAll(`.star-btn[data-sid="${sid}"][data-hid="${hid}"]`).forEach(b=>{
     const st=parseInt(b.dataset.star,10);
     b.classList.toggle('active', st<=val);
     b.classList.toggle('inactive', st>val);
   });
 });
});
</script>
</div>
</body>
</html>
