<?php
// File: dashboard/admin/star_manager.php

// ─── 1) Grab the shared bootstrap + auth guard ─────────────────────────
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// ─── 2) Handle POST actions ────────────────────────────────────────────
//
//    We expect $_POST['action'] to be one of: 'approve', 'reject', 'award'.
//    Each branch below does its work, sets a $_SESSION['flash'], and then
//    redirects back to index.php?page=star_manager.
//
// ────────────────────────────────────────────────────────────────────────
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


// ─── 3) Fetch data for display (no POST) ─────────────────────────────────

// 3.1) Load every student’s current star balance (if null, treat as zero)
$studentBalances = [];
$res = $conn->query("
    SELECT s.id AS student_id,
           s.name AS student_name,
           COALESCE(st.star_count, 0) AS star_count
      FROM students s
      LEFT JOIN stars st ON st.student_id = s.id
     ORDER BY s.name
");
while ($row = $res->fetch_assoc()) {
    $studentBalances[] = $row;
}
$res->free();

// 3.2) Load all pending redemption requests (with student name)
$pendingRequests = [];
$stmt = $conn->prepare("
    SELECT r.id,
           r.student_id,
           st.name AS student_name,
           r.reward_title,
           r.stars_required,
           r.requested_at
      FROM star_redemptions r
      JOIN students        st ON st.id = r.student_id
     WHERE r.status = 'pending'
     ORDER BY r.requested_at DESC
");
$stmt->execute();
$pendingRequests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 3.3) Generate a fresh CSRF token for all forms
$csrf = generate_csrf_token();

// 3.4) Grab any “flash” from the session (set in the POST blocks), then clear it
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


// ─── 4) Now render the page content inside the shared wrapper ────────────
?>

<div class="container-fluid p-4">

  <h2 class="mb-4 text-2xl font-semibold text-gray-800">
    <i class="bi bi-star-fill"></i> Star Manager
  </h2>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- ─────────────────────────────────────────────────────────
       4.1) Manual “Award Stars” Form
       ───────────────────────────────────────────────────────── -->
  <div class="card mb-5 shadow-sm">
    <div class="card-header"><strong>Manually Award Stars</strong></div>
    <div class="card-body">
      <form method="POST" class="row g-3">
        <input type="hidden" name="csrf_token"      value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action"          value="award">

        <div class="col-md-4">
          <label for="award_student_id" class="form-label">Select Student</label>
          <select id="award_student_id" name="award_student_id" class="form-select" required>
            <option value="">— pick a student —</option>
            <?php foreach ($studentBalances as $sbal): ?>
              <option value="<?= (int)$sbal['student_id'] ?>">
                <?= htmlspecialchars($sbal['student_name']) ?> 
                (<?= (int)$sbal['star_count'] ?> stars)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label for="award_amount" class="form-label">Amount of Stars</label>
          <input 
            type="number"
            id="award_amount"
            name="award_amount"
            min="1"
            class="form-control"
            placeholder="e.g. 10"
            required
          >
        </div>

        <div class="col-md-5">
          <label for="award_reason" class="form-label">Reason</label>
          <input 
            type="text"
            id="award_reason"
            name="award_reason"
            class="form-control"
            placeholder="e.g. ‘Excellent attendance’"
            required
          >
        </div>

        <div class="col-12 text-end">
          <button type="submit" class="btn btn-success">
            Award Stars
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ─────────────────────────────────────────────────────────
       4.2) “All Student Balances” Table
       ───────────────────────────────────────────────────────── -->
  <div class="card mb-5 shadow-sm">
    <div class="card-header"><strong>All Student Balances</strong></div>
    <div class="card-body table-responsive">
      <table class="table table-striped table-bordered mb-0">
        <thead class="table-dark">
          <tr>
            <th scope="col"># ID</th>
            <th scope="col">Student Name</th>
            <th scope="col">Current Stars</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($studentBalances as $sbal): ?>
            <tr>
              <td><?= (int)$sbal['student_id'] ?></td>
              <td><?= htmlspecialchars($sbal['student_name']) ?></td>
              <td><?= (int)$sbal['star_count'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ─────────────────────────────────────────────────────────
       4.3) “Pending Redemption Requests” Table
       ───────────────────────────────────────────────────────── -->
  <div class="card shadow-sm">
    <div class="card-header"><strong>Pending Redemption Requests</strong></div>
    <div class="card-body table-responsive">
      <?php if (empty($pendingRequests)): ?>
        <p class="text-center my-4 text-muted">No pending requests at this time.</p>
      <?php else: ?>
        <table class="table table-striped table-bordered mb-0">
          <thead class="table-light">
            <tr>
              <th scope="col"># Req ID</th>
              <th scope="col">Student</th>
              <th scope="col">Reward Title</th>
              <th scope="col">Stars Req’d</th>
              <th scope="col">Requested At</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pendingRequests as $req): ?>
              <tr>
                <td><?= (int)$req['id'] ?></td>
                <td>
                  <?= htmlspecialchars($req['student_name']) ?>
                  (ID <?= (int)$req['student_id'] ?>)
                </td>
                <td><?= htmlspecialchars($req['reward_title']) ?></td>
                <td><?= (int)$req['stars_required'] ?></td>
                <td><?= htmlspecialchars($req['requested_at']) ?></td>
                <td>
                  <!-- Approve Form -->
                  <form method="POST" class="d-inline-block me-1" 
                        onsubmit="return confirm('Approve this redemption?');">
                    <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action"        value="approve">
                    <input type="hidden" name="redemption_id" value="<?= (int)$req['id'] ?>">
                    <input type="hidden" name="reward_title"  value="<?= htmlspecialchars($req['reward_title']) ?>">
                    <button class="btn btn-sm btn-success">
                      <i class="bi bi-check-circle-fill"></i> Approve
                    </button>
                  </form>

                  <!-- Reject Form -->
                  <form method="POST" class="d-inline-block" 
                        onsubmit="return confirm('Reject this redemption?');">
                    <input type="hidden" name="csrf_token"    value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action"        value="reject">
                    <input type="hidden" name="redemption_id" value="<?= (int)$req['id'] ?>">
                    <button class="btn btn-sm btn-danger">
                      <i class="bi bi-x-circle-fill"></i> Reject
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

</div> <!-- /.container-fluid -->
