<?php
// File: dashboard/admin/students.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

// CSRF & flash
$csrf  = generate_csrf_token();
$flash = get_flash();

// 1) Filters & pagination
$search = trim($_GET['search']  ?? '');
$centre = (int)($_GET['centre']  ?? 0);
$plan   = (int)($_GET['plan']    ?? 0);
$pageNo = max(1,(int)($_GET['p'] ?? 1));
$perPage=20;
$offset = ($pageNo-1)*$perPage;

// 2) Build WHERE + params
$where='WHERE 1 '; $types=''; $params=[];
if($search!==''){
  $where.=" AND (s.name LIKE ? OR s.email LIKE ?)";
  $params[]= "%$search%"; $params[]= "%$search%"; $types.='ss';
}
if($centre){
  $where.=" AND s.centre_id=? "; $params[]=$centre; $types.='i';
}
if($plan){
  $where.=" AND sub.plan_id=? "; $params[]=$plan; $types.='i';
}

// 3) Count total
$sqlCount = "
  SELECT COUNT(*) cnt
    FROM students s
    LEFT JOIN (
      SELECT student_id, plan_id
        FROM student_subscriptions
       WHERE (student_id,subscribed_at) IN (
         SELECT student_id,MAX(subscribed_at) FROM student_subscriptions GROUP BY student_id
       )
    ) sub ON sub.student_id=s.id
  $where
";
$stmt=$conn->prepare($sqlCount);
if($types) $stmt->bind_param($types,...$params);
$stmt->execute();
$total=$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();
$totalPages=ceil($total/$perPage);

// 4) Fetch page data with current group
$sql = "
  SELECT 
    s.id, s.name, s.email, s.phone,
    c.name AS centre_name,
    sub.plan_id, p.duration_months,
    ag.label AS group_label
  FROM students s
  JOIN centres c ON c.id=s.centre_id
  LEFT JOIN (
    SELECT student_id, plan_id
      FROM student_subscriptions
     WHERE (student_id,subscribed_at) IN (
       SELECT student_id,MAX(subscribed_at) FROM student_subscriptions GROUP BY student_id
     )
  ) sub ON sub.student_id=s.id
  LEFT JOIN payment_plans p ON p.id=sub.plan_id
  LEFT JOIN student_promotions sp 
    ON sp.student_id=s.id AND sp.is_applied=1
  LEFT JOIN art_groups ag ON ag.id=sp.art_group_id
  $where
  ORDER BY s.name
  LIMIT ? OFFSET ?
";
$stmt=$conn->prepare($sql);
if($types){
  $types2=$types.'ii';
  $params2=array_merge($params,[$perPage,$offset]);
  $stmt->bind_param($types2,...$params2);
}else{
  $stmt->bind_param('ii',$perPage,$offset);
}
$stmt->execute();
$students=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5) Fetch centres & plans for filters
$centresList=$conn->query("SELECT id,name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$plansList  =$conn->query("
  SELECT id, CONCAT(plan_name,' (',duration_months,'m)') AS label
    FROM payment_plans
   GROUP BY id
   ORDER BY plan_name
")->fetch_all(MYSQLI_ASSOC);
?>

<div class="max-w-6xl mx-auto p-6 space-y-6 bg-white rounded-lg shadow">

  <div class="flex justify-between items-center">
    <h2 class="text-2xl font-semibold">Manage Students</h2>
    <a href="?page=add_student" class="bg-blue-600 text-white px-4 py-2 rounded hover:opacity-90">
      + Add Student
    </a>
  </div>

  <?php if($flash): ?>
    <div class="p-3 bg-<?= $flash['type']==='danger'?'red':'green' ?>-100 
                border border-<?= $flash['type']==='danger'?'red':'green' ?>-400 
                text-<?= $flash['type']==='danger'?'red':'green' ?>-700 rounded">
      <?=htmlspecialchars($flash['msg'])?>
    </div>
  <?php endif; ?>

  <!-- Filters -->
  <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <input type="hidden" name="page" value="students">

    <input type="text" name="search" placeholder="Search name/email…"
           value="<?=htmlspecialchars($search)?>"
           class="border p-2 rounded">

    <select name="centre" class="border p-2 rounded">
      <option value="">All Centres</option>
      <?php foreach($centresList as $c): ?>
        <option value="<?=$c['id']?>" <?=$centre===$c['id']?'selected':''?>>
          <?=htmlspecialchars($c['name'])?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="plan" class="border p-2 rounded">
      <option value="">All Plans</option>
      <?php foreach($plansList as $p): ?>
        <option value="<?=$p['id']?>" <?=$plan===$p['id']?'selected':''?>>
          <?=htmlspecialchars($p['label'])?>
        </option>
      <?php endforeach; ?>
    </select>

    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">
      Filter
    </button>
  </form>

  <!-- Students Table -->
  <div class="overflow-x-auto">
    <table class="w-full table-auto bg-white divide-y divide-gray-200">
      <thead class="bg-gray-100">
        <tr>
          <th class="px-4 py-2 text-left">ID</th>
          <th class="px-4 py-2 text-left">Name</th>
          <th class="px-4 py-2 text-left">Email</th>
          <th class="px-4 py-2 text-left">Phone</th>
          <th class="px-4 py-2 text-left">Centre</th>
          <th class="px-4 py-2 text-left">Stage</th>
          <th class="px-4 py-2 text-left">Duration</th>
          <th class="px-4 py-2 text-left">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-100">
        <?php if(!$students): ?>
          <tr><td colspan="8" class="p-4 text-center text-gray-500">No students found.</td></tr>
        <?php else: foreach($students as $s): ?>
          <tr>
            <td class="px-4 py-2"><?= $s['id'] ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($s['name']) ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($s['email']) ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($s['phone']) ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($s['centre_name']) ?></td>
            <td class="px-4 py-2"><?= htmlspecialchars($s['group_label'] ?? '—') ?></td>
            <td class="px-4 py-2"><?= (int)$s['duration_months'] ?>m</td>
            <td class="px-4 py-2 flex space-x-2">
              <a href="?page=edit_student&id=<?=$s['id']?>"
                 class="px-2 py-1 border border-blue-600 text-blue-600 rounded hover:bg-blue-600 hover:text-white">
                Edit
              </a>
              <form method="post" action="?page=delete_student" onsubmit="return confirm('Delete?')">
                <input type="hidden" name="csrf_token" value="<?=$csrf?>">
                <input type="hidden" name="student_id" value="<?=$s['id']?>">
                <button type="submit"
                        class="px-2 py-1 border border-red-600 text-red-600 rounded hover:bg-red-600 hover:text-white">
                  Delete
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if($totalPages>1): ?>
    <nav class="flex justify-center mt-6">
      <ul class="inline-flex -space-x-px">
        <!-- Previous -->
        <li>
          <a href="?<?=http_build_query(array_merge($_GET,['p'=>max(1,$pageNo-1)]))?>"
             class="px-3 py-1 border border-gray-300 text-gray-500 rounded-l hover:bg-gray-100">
            Prev
          </a>
        </li>
        <!-- Pages -->
        <?php for($i=1;$i<=$totalPages;$i++): ?>
          <li>
            <a href="?<?=http_build_query(array_merge($_GET,['p'=>$i]))?>"
               class="px-3 py-1 border border-gray-300 <?= $i===$pageNo
                 ? 'bg-blue-600 text-white'
                 : 'text-gray-700 hover:bg-gray-100' ?> ">
              <?=$i?>
            </a>
          </li>
        <?php endfor; ?>
        <!-- Next -->
        <li>
          <a href="?<?=http_build_query(array_merge($_GET,['p'=>min($totalPages,$pageNo+1)]))?>"
             class="px-3 py-1 border border-gray-300 text-gray-500 rounded-r hover:bg-gray-100">
            Next
          </a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>
