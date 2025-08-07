<?php
// File: dashboard/admin/students.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$csrf  = generate_csrf_token();
$flash = get_flash();

// ─── DELETE HANDLERS ────────────────────────────────────────────────
// Bulk delete?
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    verify_csrf_token($_POST['csrf_token']);
    $ids = array_map('intval', $_POST['student_ids'] ?? []);
    if ($ids) {
        $in = implode(',', $ids);

        // 1) Fetch the user_ids before deleting
        $userRes = $conn->query("SELECT user_id FROM students WHERE id IN ($in) AND user_id IS NOT NULL");
        $userIds = [];
        while ($row = $userRes->fetch_assoc()) {
            $userIds[] = (int)$row['user_id'];
        }

        // 2) Delete the students
        $conn->query("DELETE FROM students WHERE id IN ($in)");

        // 3) Delete the matching users
        if ($userIds) {
            $inUsers = implode(',', $userIds);
            $conn->query("DELETE FROM users WHERE id IN ($inUsers)");
        }

        set_flash(count($ids) . ' student(s) deleted (and their login accounts).', 'success');
    }
    header('Location: ?page=students');
    exit;
}

// Individual delete?
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['individual_delete'])) {
    verify_csrf_token($_POST['csrf_token']);
    $id = (int)($_POST['student_id'] ?? 0);
    if ($id) {
        // 1) look up their user_id
        $stmt = $conn->prepare("SELECT user_id FROM students WHERE id = ?");
        $stmt->bind_param('i',$id);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        // 2) delete the student
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        // 3) delete their user row if one existed
        if ($userId) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param('i',$userId);
            $stmt->execute();
            $stmt->close();
        }

        set_flash('Student (and login) deleted.', 'success');
    }
    header('Location: ?page=students');
    exit;
}

// ─── FILTER & PAGINATION SETUP ──────────────────────────────────────
$search = trim($_GET['search']  ?? '');
$centre = (int)($_GET['centre']  ?? 0);
$plan   = (int)($_GET['plan']    ?? 0);
$pageNo = max(1, (int)($_GET['p'] ?? 1));
$perPage= 20;
$offset = ($pageNo - 1) * $perPage;

$where   = 'WHERE 1=1 ';
$types   = '';
$params  = [];
if ($search !== '') {
    $where .= " AND (s.name LIKE ? OR s.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types   .= 'ss';
}
if ($centre) {
    $where .= " AND s.centre_id = ?";
    $params[] = $centre;
    $types   .= 'i';
}
if ($plan) {
    $where .= " AND sub.plan_id = ?";
    $params[] = $plan;
    $types   .= 'i';
}

// ─── TOTAL COUNT ───────────────────────────────────────────────────
$sqlCount = "
  SELECT COUNT(*) AS cnt
    FROM students s
    LEFT JOIN (
      SELECT student_id, plan_id
        FROM student_subscriptions
       WHERE (student_id, subscribed_at) IN (
         SELECT student_id, MAX(subscribed_at)
           FROM student_subscriptions
          GROUP BY student_id
       )
    ) sub ON sub.student_id = s.id
  $where
";
$stmt = $conn->prepare($sqlCount);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
$stmt->close();
$totalPages = (int)ceil($total / $perPage);

// ─── FETCH PAGE DATA ───────────────────────────────────────────────
$sql = "
  SELECT 
    s.id, s.name, s.email, s.phone,
    c.name AS centre_name,
    sub.plan_id, p.duration_months,
    ag.label AS group_label
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  LEFT JOIN (
    SELECT student_id, plan_id, subscribed_at
      FROM student_subscriptions
     WHERE (student_id, subscribed_at) IN (
       SELECT student_id, MAX(subscribed_at)
         FROM student_subscriptions
        GROUP BY student_id
     )
  ) sub ON sub.student_id = s.id
  LEFT JOIN payment_plans p ON p.id = sub.plan_id
  LEFT JOIN (
      SELECT sp1.*
        FROM student_promotions sp1
        INNER JOIN (
            SELECT student_id, MAX(effective_date) AS max_date
            FROM student_promotions
            WHERE is_applied=1
            GROUP BY student_id
        ) sp2 ON sp1.student_id=sp2.student_id AND sp1.effective_date=sp2.max_date
        WHERE sp1.is_applied=1
  ) sp ON sp.student_id = s.id
  LEFT JOIN art_groups ag ON ag.id = sp.art_group_id
  $where
  ORDER BY s.name
  LIMIT ? OFFSET ?
";
$params2 = $params;
$types2  = $types;
$params2[] = $perPage;
$params2[] = $offset;
$types2   .= 'ii';

$stmt = $conn->prepare($sql);
if (!empty($types2)) {
    $stmt->bind_param($types2, ...$params2);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── FILTER DROPDOWNS ──────────────────────────────────────────────
$centresList = $conn->query("SELECT id, name FROM centres ORDER BY name")
                    ->fetch_all(MYSQLI_ASSOC);
$plansList   = $conn->query("
    SELECT id, CONCAT(plan_name,' (',duration_months,'m)') AS label
      FROM payment_plans
     ORDER BY plan_name
")->fetch_all(MYSQLI_ASSOC);
?>

<div class="max-w-7xl mx-auto p-6 space-y-6 bg-white rounded-lg shadow">

  <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
    <h2 class="text-2xl font-semibold">👩‍🎓 Manage Students</h2>
    <a href="?page=add_student" class="bg-purple-600 text-white px-4 py-2 rounded hover:opacity-90">
      ➕ Add Student
    </a>
  </div>

  <!-- Flash Message -->
  <?php if ($flash): ?>
    <div id="toastMsg"
         class="fixed top-4 right-4 z-50 bg-<?= $flash['type'] === 'danger' ? 'red' : 'green' ?>-600
                text-white px-4 py-2 rounded shadow">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <script>
      setTimeout(() => {
        document.getElementById('toastMsg')?.remove();
      }, 4000);
    </script>
  <?php endif; ?>

  <!-- Filters -->
  <form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
    <input type="hidden" name="page" value="students">
    <input type="text" name="search" placeholder="Search name, email or group…"
           value="<?= htmlspecialchars($search) ?>"
           class="border p-2 rounded"/>
    <select name="centre" class="border p-2 rounded">
      <option value="">All Centres</option>
      <?php foreach ($centresList as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $centre === $c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="plan" class="border p-2 rounded">
      <option value="">All Plans</option>
      <?php foreach ($plansList as $p): ?>
        <option value="<?= $p['id'] ?>" <?= $plan === $p['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit"
            class="bg-purple-600 text-white px-4 py-2 rounded hover:bg-purple-700">
      Filter
    </button>
  </form>

  <!-- Student Table with Bulk Delete -->
  <form id="bulkDeleteForm" method="post" action="?page=students">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="bulk_delete" value="1">

    <div class="overflow-x-auto">
      <table class="w-full table-auto bg-white divide-y divide-gray-200">
        <thead class="bg-gray-100">
          <tr>
            <th class="px-4 py-2">
              <input type="checkbox" onclick="toggleAll(this)">
            </th>
            <th class="px-4 py-2 text-left">ID</th>
            <th class="px-4 py-2 text-left">Name</th>
            <th class="px-4 py-2 text-left">Email</th>
            <th class="px-4 py-2 text-left">Phone</th>
            <th class="px-4 py-2 text-left">Centre</th>
            <th class="px-4 py-2 text-left">Group</th>
            <th class="px-4 py-2 text-left">Dur (m)</th>
            <th class="px-4 py-2 text-left">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (empty($students)): ?>
            <tr>
              <td colspan="9" class="p-4 text-center text-gray-500">
                No students found.
              </td>
            </tr>
          <?php else: foreach ($students as $s): ?>
            <tr>
              <td class="px-4 py-2">
                <input type="checkbox" name="student_ids[]" value="<?= $s['id'] ?>">
              </td>
              <td class="px-4 py-2"><?= $s['id'] ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($s['name']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($s['email']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($s['phone']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($s['centre_name']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($s['group_label'] ?? '—') ?></td>
              <td class="px-4 py-2"><?= (int)$s['duration_months'] ?></td>
              <td class="px-4 py-2 flex flex-wrap gap-2">
                <a href="?page=edit_student&id=<?= $s['id'] ?>"
                   class="px-2 py-1 border border-purple-600 text-purple-600 rounded
                          hover:bg-purple-600 hover:text-white">
                  Edit
                </a>
                <button type="button"
                        onclick="confirmIndividualDelete(<?= $s['id'] ?>)"
                        class="px-2 py-1 border border-red-600 text-red-600 rounded
                               hover:bg-red-600 hover:text-white">
                  Delete
                </button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if (!empty($students)): ?>
      <div class="mt-4 flex justify-end">
        <button type="button"
                onclick="confirmBulkDelete()"
                class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700">
          🗑 Delete Selected
        </button>
      </div>
    <?php endif; ?>
  </form>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <nav class="flex justify-center mt-6">
      <ul class="inline-flex -space-x-px">
        <li>
          <a href="?<?= http_build_query(array_merge($_GET, ['p' => max(1, $pageNo - 1)])) ?>"
             class="px-3 py-1 border rounded-l hover:bg-gray-100">Prev</a>
        </li>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
          <li>
            <a href="?<?= http_build_query(array_merge($_GET, ['p' => $i])) ?>"
               class="px-3 py-1 border <?= $i === $pageNo ? 'bg-purple-600 text-white' : 'hover:bg-gray-100' ?>">
              <?= $i ?>
            </a>
          </li>
        <?php endfor; ?>
        <li>
          <a href="?<?= http_build_query(array_merge($_GET, ['p' => min($totalPages, $pageNo + 1)])) ?>"
             class="px-3 py-1 border rounded-r hover:bg-gray-100">Next</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>

</div>

<!-- Confirmation Modal for Bulk Delete -->
<div id="confirmModal"
     class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center">
  <div class="bg-white rounded-lg p-6 shadow max-w-sm w-full">
    <h2 class="text-lg font-semibold mb-3">Confirm Bulk Delete</h2>
    <p class="mb-4">Are you sure you want to delete the selected students?</p>
    <div class="flex justify-end gap-2">
      <button onclick="closeModal()"
              class="px-4 py-2 border rounded hover:bg-gray-100">
        Cancel
      </button>
      <button onclick="submitBulkDelete()"
              class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">
        Delete
      </button>
    </div>
  </div>
</div>

<!-- Individual Delete Form -->
<form method="post" action="?page=students" id="individualDeleteForm">
  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
  <input type="hidden" name="individual_delete" value="1">
  <input type="hidden" name="student_id" id="deleteStudentId">
</form>

<script>
  function confirmBulkDelete() {
    const anyChecked = document.querySelectorAll('input[name="student_ids[]"]:checked').length > 0;
    if (!anyChecked) return alert("Please select at least one student.");
    document.getElementById('confirmModal').classList.remove('hidden');
  }
  function closeModal() {
    document.getElementById('confirmModal').classList.add('hidden');
  }
  function submitBulkDelete() {
    document.getElementById('bulkDeleteForm').submit();
  }
  function confirmIndividualDelete(id) {
    if (confirm("Are you sure you want to delete this student?")) {
      document.getElementById('deleteStudentId').value = id;
      document.getElementById('individualDeleteForm').submit();
    }
  }
  function toggleAll(el) {
    document.querySelectorAll('input[name="student_ids[]"]')
            .forEach(cb => cb.checked = el.checked);
  }
</script>
