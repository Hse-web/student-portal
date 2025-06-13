<?php
// File: dashboard/admin/star_manager.php
// This page is included via index.php?page=star_manager

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// Handle POST actions...
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 2.1) CSRF check first
    if (empty($_POST['csrf_token']) || ! verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash'] = [
            'type' => 'danger',
            'msg'  => 'Invalid session (CSRF). Please try again.'
        ];
        header('Location: index.php?page=star_manager');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // ─── 2.2) “Approve” a redemption request ───────────────────────────────
    if ($action === 'approve') {
        $rid = (int)($_POST['redemption_id'] ?? 0);
        if ($rid < 1) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid request ID.'];
        }
        else {
            $admin_id = (int)$_SESSION['user_id'];
            $conn->begin_transaction();
            try {
                // 2.2.1) Load the pending redemption row
                $stmt = $conn->prepare("
                    SELECT student_id, stars_required
                      FROM star_redemptions
                     WHERE id = ? 
                       AND status = 'pending'
                     LIMIT 1
                ");
                $stmt->bind_param('i', $rid);
                $stmt->execute();
                $stmt->bind_result($student_id, $stars_required);
                $found = $stmt->fetch();
                $stmt->close();

                if (! $found) {
                    throw new Exception('No pending request found (maybe already processed).');
                }

                // 2.2.2) Fetch the student’s current star balance
                $stmt2 = $conn->prepare("
                    SELECT star_count 
                      FROM stars 
                     WHERE student_id = ?
                     LIMIT 1
                ");
                $stmt2->bind_param('i', $student_id);
                $stmt2->execute();
                $stmt2->bind_result($currentBalance);
                $stmt2->fetch();
                $stmt2->close();

                if ($currentBalance === null) {
                    // If no row in `stars` yet, insert one with 0
                    $ins0 = $conn->prepare("
                        INSERT INTO stars (student_id, star_count)
                             VALUES (?, 0)
                    ");
                    $ins0->bind_param('i', $student_id);
                    $ins0->execute();
                    $ins0->close();
                    $currentBalance = 0;
                }

                if ($currentBalance < $stars_required) {
                    throw new Exception('Student does not have enough stars. Automatically rejecting.');
                }

                // 2.2.3) Deduct from `stars` table
                $stmt3 = $conn->prepare("
                    UPDATE stars 
                       SET star_count = star_count - ? 
                     WHERE student_id = ?
                ");
                $stmt3->bind_param('ii', $stars_required, $student_id);
                $stmt3->execute();
                $stmt3->close();

                // 2.2.4) Log into `star_history` (negative number)
                $reason = 'Redeemed Request #' . $rid . ' → ' . $_POST['reward_title'];
                $negative = -1 * $stars_required;
                $stmt4 = $conn->prepare("
                    INSERT INTO star_history (student_id, event_date, stars, reason)
                    VALUES (?, CURDATE(), ?, ?)
                ");
                $stmt4->bind_param('iis', $student_id, $negative, $reason);
                $stmt4->execute();
                $stmt4->close();

                // 2.2.5) Mark the redemption row as “approved”
                $stmt5 = $conn->prepare("
                    UPDATE star_redemptions
                       SET status = 'approved',
                           processed_at = NOW(),
                           processed_by = ?
                     WHERE id = ?
                       AND status = 'pending'
                ");
                $stmt5->bind_param('ii', $admin_id, $rid);
                $stmt5->execute();
                $stmt5->close();

                $conn->commit();
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'msg'  => "Redemption #{$rid} approved and {$stars_required} stars deducted from student #{$student_id}."
                ];
            }
            catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = [
                    'type' => 'danger',
                    'msg'  => 'Error approving redemption: ' . $e->getMessage()
                ];
            }
        }

        header('Location: index.php?page=star_manager');
        exit;
    }

    // ─── 2.3) “Reject” a redemption request ────────────────────────────────
    elseif ($action === 'reject') {
        $rid = (int)($_POST['redemption_id'] ?? 0);
        if ($rid < 1) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid request ID.'];
        }
        else {
            $admin_id = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare("
                UPDATE star_redemptions
                   SET status = 'rejected',
                       processed_at = NOW(),
                       processed_by = ?
                 WHERE id = ?
                   AND status = 'pending'
            ");
            $stmt->bind_param('ii', $admin_id, $rid);
            $stmt->execute();
            if ($stmt->affected_rows) {
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'msg'  => "Redemption #{$rid} has been rejected."
                ];
            } else {
                $_SESSION['flash'] = [
                    'type' => 'danger',
                    'msg'  => "Could not reject redemption #{$rid}. It may have already been processed."
                ];
            }
            $stmt->close();
        }
        header('Location: index.php?page=star_manager');
        exit;
    }

    // ─── 2.4) “Award” stars manually to a student ──────────────────────────
    elseif ($action === 'award') {
        $stu    = (int)($_POST['award_student_id'] ?? 0);
        $amt    = (int)($_POST['award_amount'] ?? 0);
        $reason = trim($_POST['award_reason'] ?? '');

        if ($stu < 1 || $amt <= 0 || $reason === '') {
            $_SESSION['flash'] = [
                'type'=>'danger',
                'msg'=>'You must select a student, enter an amount (> 0), and provide a reason.'
            ];
        }
        else {
            $admin_id = (int)$_SESSION['user_id'];
            $conn->begin_transaction();
            try {
                // 2.4.1) Ensure `stars` row exists
                $stmt0 = $conn->prepare("
                    SELECT star_count 
                      FROM stars 
                     WHERE student_id = ?
                ");
                $stmt0->bind_param('i', $stu);
                $stmt0->execute();
                $stmt0->bind_result($existingBalance);
                $exists = $stmt0->fetch();
                $stmt0->close();

                if (!$exists) {
                    // Insert new row with 0
                    $ins0 = $conn->prepare("
                        INSERT INTO stars (student_id, star_count) VALUES (?, 0)
                    ");
                    $ins0->bind_param('i', $stu);
                    $ins0->execute();
                    $ins0->close();
                }

                // 2.4.2) Add stars
                $stmt1 = $conn->prepare("
                    UPDATE stars
                       SET star_count = star_count + ?
                     WHERE student_id = ?
                ");
                $stmt1->bind_param('ii', $amt, $stu);
                $stmt1->execute();
                $stmt1->close();

                // 2.4.3) Log into star_history
                $stmt2 = $conn->prepare("
                    INSERT INTO star_history (student_id, event_date, stars, reason)
                    VALUES (?, CURDATE(), ?, ?)
                ");
                $stmt2->bind_param('iis', $stu, $amt, $reason);
                $stmt2->execute();
                $stmt2->close();

                $conn->commit();
                $_SESSION['flash'] = [
                    'type'=>'success',
                    'msg'=>"Awarded {$amt} stars to student #{$stu} (reason: “{$reason}”)."
                ];
            }
            catch (\Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = [
                    'type'=>'danger',
                    'msg'=>'Error while awarding stars: '.$e->getMessage()
                ];
            }
        }
        header('Location: index.php?page=star_manager');
        exit;
    }

    // ─── 2.5) Unknown action ───────────────────────────────────────────────
    else {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Unknown admin action.'];
        header('Location: index.php?page=star_manager');
        exit;
    }
}

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
