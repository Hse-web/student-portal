<?php
// File: dashboard/admin/homework_centerwise.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

// ─── Handle Feedback Save & Proxy Upload ─────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_feedback') {
  if (!verify_csrf_token($_POST['csrf_token']??'')) {
    set_flash('Session expired; please try again.', 'danger');
  } else {
    foreach($_POST['feedback'] as $sid => $hwArray) {
      foreach($hwArray as $hwId => $fb) {
        $stmt = $conn->prepare("UPDATE homework_submissions SET feedback=? WHERE student_id=? AND assignment_id=?");
        $stmt->bind_param('sii', $fb, $sid, $hwId);
        $stmt->execute();
        $stmt->close();
      }
    }

    // Handle Shown in Class and Proxy Uploads
    foreach($_POST['shown_in_class'] ?? [] as $sid => $hws) {
      foreach($hws as $hwid => $val) {
        $shown = 1;
        $proxyPath = null;
        if (isset($_FILES['proxy_file']['tmp_name'][$sid][$hwid]) && is_uploaded_file($_FILES['proxy_file']['tmp_name'][$sid][$hwid])) {
          $uploadDir = '/uploads/homework/proxies/';
          $filename = uniqid('proxy_').basename($_FILES['proxy_file']['name'][$sid][$hwid]);
          $targetPath = $uploadDir . $filename;
          move_uploaded_file($_FILES['proxy_file']['tmp_name'][$sid][$hwid], __DIR__ . '/../../' . $targetPath);
          $proxyPath = $targetPath;
        }

        $stmt = $conn->prepare("UPDATE homework_submissions SET shown_in_class=?, proxy_file_path=IFNULL(?, proxy_file_path) WHERE student_id=? AND assignment_id=?");
        $stmt->bind_param('ssii', $shown, $proxyPath, $sid, $hwid);
        $stmt->execute();
        $stmt->close();
      }
    }

    set_flash('Feedback & review info saved.', 'success');
  }
  header('Location:?page=homework_centerwise'
       .'&centre='.urlencode($_GET['centre']??'')
       .'&group='.urlencode($_GET['group']??'')
       .'&month='.urlencode($_GET['month']??''));
  exit;
}

// ─── Filters ─────────────────────────────────────────────────────────
$centre = (int)($_GET['centre']??0);
$group  = trim($_GET['group']??'');
$month  = $_GET['month'] ?? date('Y-m');
$where = 'WHERE 1=1 '; $params = []; $types='';
if($centre){ $where .= ' AND s.centre_id=? '; $params[] = $centre; $types .= 'i'; }
if($group!==''){ $where .= ' AND s.group_name=? '; $params[] = $group; $types .= 's'; }
if($month!==''){ $where .= ' AND DATE_FORMAT(ha.date_assigned,"%Y-%m")=? '; $params[] = $month; $types .= 's'; }

// ─── Fetch Data ──────────────────────────────────────────────────────
$sql = "
  SELECT
    s.id AS student_id,
    s.name,
    s.group_name AS `group`,
    c.name AS centre,
    ha.id AS hw_id,
    ha.title,
    ha.date_assigned,
    hs.file_path,
    hs.proxy_file_path,
    hs.shown_in_class,
    hs.submitted_at,
    hs.feedback,
    hs.star_given
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  LEFT JOIN homework_assigned ha ON ha.student_id = s.id
  LEFT JOIN homework_submissions hs ON hs.assignment_id = ha.id AND hs.student_id = s.id
  {$where}
  ORDER BY s.name, ha.date_assigned
";
$stmt = $conn->prepare($sql);
if($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Dropdowns ───────────────────────────────────────────────────────
$centresList = $conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$groupsList = $conn->query("SELECT DISTINCT group_name FROM students ORDER BY group_name")->fetch_all(MYSQLI_NUM);
$csrf = generate_csrf_token();
$flash = get_flash();
?>
<div class="max-w-6xl mx-auto p-6 bg-white rounded-lg shadow space-y-6">
  <h2 class="text-2xl font-semibold">📝 Homework Review (Center-wise)</h2>
  <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <input type="hidden" name="page" value="homework_centerwise">
    <select name="centre" class="border p-2 rounded">
      <option value="">All Centres</option>
      <?php foreach($centresList as $c): ?>
        <option value="<?=$c['id']?>" <?=$centre===$c['id']?'selected':''?>><?=htmlspecialchars($c['name'])?></option>
      <?php endforeach; ?>
    </select>
    <select name="group" class="border p-2 rounded">
      <option value="">All Stages</option>
      <?php foreach($groupsList as [$g]): ?>
        <option value="<?=htmlspecialchars($g)?>" <?=$group===$g?'selected':''?>><?=htmlspecialchars($g)?></option>
      <?php endforeach; ?>
    </select>
    <input type="month" name="month" value="<?=htmlspecialchars($month)?>" class="border p-2 rounded">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:opacity-90">Filter</button>
  </form>

  <?php if($flash): ?>
    <div class="p-3 bg-<?= $flash['type']==='danger'?'red':'green' ?>-100 border border-<?= $flash['type']==='danger'?'red':'green' ?>-400 text-<?= $flash['type']==='danger'?'red':'green' ?>-700 rounded">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?=$csrf?>">
    <input type="hidden" name="action" value="save_feedback">

    <div class="overflow-x-auto">
      <table class="min-w-full bg-white divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-3 py-2">Student</th>
            <th class="px-3 py-2">Centre</th>
            <th class="px-3 py-2">Stage</th>
            <th class="px-3 py-2">HW Title</th>
            <th class="px-3 py-2 text-center">Submission</th>
            <th class="px-3 py-2 text-center">Stars</th>
            <th class="px-3 py-2">Feedback</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$data): ?>
            <tr><td colspan="7" class="p-4 text-center text-gray-500">No records found.</td></tr>
          <?php else: foreach($data as $row): ?>
            <tr>
              <td class="px-3 py-2"><?=htmlspecialchars($row['name'])?></td>
              <td class="px-3 py-2"><?=htmlspecialchars($row['centre'])?></td>
              <td class="px-3 py-2"><?=htmlspecialchars($row['group'])?></td>
              <td class="px-3 py-2"><?=htmlspecialchars($row['title'])?></td>
              <td class="px-3 py-2 text-center">
                <?php if ($row['file_path'] || $row['proxy_file_path']): ?>
                  <a href="<?=htmlspecialchars($row['file_path'] ?: $row['proxy_file_path'])?>" target="_blank">
                    <img src="<?=htmlspecialchars($row['file_path'] ?: $row['proxy_file_path'])?>" class="h-16 w-auto border rounded shadow mx-auto" alt="HW">
                  </a><br>✅
                <?php elseif ($row['shown_in_class']): ?>
                  👁️<br><small>Shown</small>
                <?php else: ?>
                  ❌
                <?php endif; ?>
                <div class="mt-1">
                  <label class="text-xs">
                    <input type="checkbox" name="shown_in_class[<?= (int)$row['student_id'] ?>][<?= (int)$row['hw_id'] ?>]" value="1" <?= $row['shown_in_class'] ? 'checked' : '' ?>>
                    Shown in Class
                  </label>
                </div>
                <input type="file" name="proxy_file[<?= (int)$row['student_id'] ?>][<?= (int)$row['hw_id'] ?>]" accept="image/*" class="mt-1 w-full text-xs">
              </td>
              <td class="px-3 py-2 text-center"><?= $row['star_given'] ? "⭐ {$row['star_given']}" : '—' ?></td>
              <td class="px-3 py-2">
               <textarea name="feedback[<?= (int)$row['student_id'] ?>][<?= (int)$row['hw_id'] ?>]" rows="2" class="w-full border rounded p-1"><?=htmlspecialchars($row['feedback'] ?? '')?></textarea>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="text-right mt-4">
      <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:opacity-90">Save Feedback</button>
    </div>
  </form>
</div>
