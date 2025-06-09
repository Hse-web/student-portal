<?php
// File: dashboard/admin/star_manager.php
// This page is included via index.php?page=star_manager

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// Handle POST actions...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid session (CSRF). Please try again.'];
        echo "<script>window.location.href='index.php?page=star_manager';</script>";
        exit;
    }

    $action = $_POST['action'] ?? '';
    // ... existing POST logic unchanged ...
}

// Fetch student balances and pending requests...

$studentBalances = [];
$res = $conn->query("SELECT s.id AS student_id, s.name AS student_name, COALESCE(st.star_count,0) AS star_count FROM students s LEFT JOIN stars st ON st.student_id=s.id ORDER BY s.name");
while ($r = $res->fetch_assoc()) {
    $studentBalances[] = $r;
}

$pendingRequests = [];
$stmt = $conn->prepare(
    "SELECT r.id, r.student_id, st.name AS student_name, r.reward_title, r.stars_required, r.requested_at
     FROM star_redemptions r
     JOIN students st ON st.id=r.student_id
     WHERE r.status='pending'
     ORDER BY r.requested_at DESC"
);
$stmt->execute();
$pendingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$csrf = generate_csrf_token();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<div class="max-w-7xl mx-auto p-6 space-y-8">
  <h1 class="text-3xl font-bold text-gray-800">
    <span class="inline-block mr-2">⭐</span>Star Manager
  </h1>

  <?php if ($flash): ?>
    <div class="px-4 py-3 rounded-md text-white <?= $flash['type']==='danger' ? 'bg-red-600' : 'bg-green-600' ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Award Stars Form -->
  <section class="bg-white shadow rounded-lg p-6">
    <h2 class="text-xl font-semibold mb-4">Manually Award Stars</h2>
    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="award">

      <div>
        <label class="block text-sm font-medium text-gray-700">Select Student</label>
        <select name="award_student_id" required
                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="">— pick a student —</option>
          <?php foreach ($studentBalances as $sb): ?>
            <option value="<?= $sb['student_id'] ?>">
              <?= htmlspecialchars($sb['student_name']) ?> (<?= $sb['star_count'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700">Amount of Stars</label>
        <input type="number" name="award_amount" min="1" required
               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
               placeholder="e.g. 10">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700">Reason</label>
        <input type="text" name="award_reason" required
               class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:ring-indigo-500 focus:border-indigo-500"
               placeholder="e.g. Excellent attendance">
      </div>

      <div class="md:col-span-3 text-right">
        <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">
          Award Stars
        </button>
      </div>
    </form>
  </section>

  <!-- Student Balances -->
  <section class="bg-white shadow rounded-lg p-6">
    <h2 class="text-xl font-semibold mb-4">All Student Balances</h2>
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">ID</th>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Name</th>
            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Stars</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <?php foreach ($studentBalances as $sb): ?>
            <tr>
              <td class="px-4 py-2 text-sm text-gray-700"><?= $sb['student_id'] ?></td>
              <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($sb['student_name']) ?></td>
              <td class="px-4 py-2 text-sm text-gray-700"><?= $sb['star_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- Pending Redemption Requests -->
  <section class="bg-white shadow rounded-lg p-6">
    <h2 class="text-xl font-semibold mb-4">Pending Redemption Requests</h2>
    <?php if (empty($pendingRequests)): ?>
      <p class="text-gray-500 text-center">No pending requests at this time.</p>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Req ID</th>
              <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Student</th>
              <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Reward</th>
              <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Stars Req'd</th>
              <th class="px-4 py-2 text-left text-sm font-medium text-gray-700">Requested At</th>
              <th class="px-4 py-2 text-center text-sm font-medium text-gray-700">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($pendingRequests as $req): ?>
              <tr>
                <td class="px-4 py-2 text-sm text-gray-700"><?= $req['id'] ?></td>
                <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($req['student_name']) ?></td>
                <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($req['reward_title']) ?></td>
                <td class="px-4 py-2 text-sm text-gray-700"><?= $req['stars_required'] ?></td>
                <td class="px-4 py-2 text-sm text-gray-700"><?= htmlspecialchars($req['requested_at']) ?></td>
                <td class="px-4 py-2 text-sm text-center space-x-2">
                  <form method="POST" class="inline-block" onsubmit="return confirm('Approve?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="redemption_id" value="<?= $req['id'] ?>">
                    <input type="hidden" name="reward_title" value="<?= htmlspecialchars($req['reward_title']) ?>">
                    <button class="px-2 py-1 bg-green-500 text-white rounded hover:bg-green-600">✔️</button>
                  </form>
                  <form method="POST" class="inline-block" onsubmit="return confirm('Reject?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="redemption_id" value="<?= $req['id'] ?>">
                    <button class="px-2 py-1 bg-red-500 text-white rounded hover:bg-red-600">❌</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</div>
