<?php
// File: dashboard/admin/star_manager.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

$csrf = generate_csrf_token();

// ── Handle POST actions ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || ! verify_csrf_token($_POST['csrf_token'])) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid session (CSRF).'];
        header('Location: ?page=star_manager');
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $rid = (int)($_POST['redemption_id'] ?? 0);
        if ($rid < 1) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid request ID.'];
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT student_id, stars_required FROM star_redemptions WHERE id = ? AND status = 'pending' LIMIT 1");
                $stmt->bind_param('i', $rid);
                $stmt->execute();
                $stmt->bind_result($student_id, $stars_required);
                if (!$stmt->fetch()) throw new Exception('No pending request.');
                $stmt->close();

                $stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id=?");
                $stmt->bind_param('i',$student_id);
                $stmt->execute();
                $stmt->bind_result($currentBalance);
                $exists = $stmt->fetch();
                $stmt->close();
                if (!$exists) {
                    $ins0 = $conn->prepare("INSERT INTO stars(student_id,star_count) VALUES(?,0)");
                    $ins0->bind_param('i',$student_id);
                    $ins0->execute();
                    $ins0->close();
                    $currentBalance = 0;
                }

                if ($currentBalance < $stars_required) throw new Exception('Insufficient stars.');

                $stmt = $conn->prepare("UPDATE stars SET star_count = star_count - ? WHERE student_id = ?");
                $stmt->bind_param('ii',$stars_required,$student_id);
                $stmt->execute();
                $stmt->close();

                $reason = 'Redeemed Request #'.$rid.' → '.$_POST['reward_title'];
                $neg = -1 * $stars_required;
                $stmt = $conn->prepare("INSERT INTO star_history(student_id,event_date,stars,reason) VALUES(?,CURDATE(),?,?)");
                $stmt->bind_param('iis',$student_id,$neg,$reason);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE star_redemptions SET status='approved', processed_at=NOW(), processed_by=? WHERE id=? AND status='pending'");
                $stmt->bind_param('ii',$_SESSION['user_id'],$rid);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Redemption #{$rid} approved."];

                create_notification($conn, [$student_id], 'Redemption Approved', "Your redemption request #{$rid} has been approved and {$stars_required} stars deducted.");
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Error: '.$e->getMessage()];
            }
        }
        header('Location: ?page=star_manager');
        exit;
    }

    if ($action === 'reject') {
        $rid = (int)($_POST['redemption_id'] ?? 0);
        if ($rid < 1) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Invalid request ID.'];
        } else {
            $row = $conn->query("SELECT student_id FROM star_redemptions WHERE id={$rid}")->fetch_assoc();
            $student_id = (int)($row['student_id'] ?? 0);

            $stmt = $conn->prepare("UPDATE star_redemptions SET status='rejected', processed_at=NOW(), processed_by=? WHERE id=? AND status='pending'");
            $stmt->bind_param('ii',$_SESSION['user_id'],$rid);
            $stmt->execute();
            $stmt->close();

            $_SESSION['flash'] = ['type'=>'warning','msg'=>"Redemption #{$rid} rejected."];

            if ($student_id) {
              create_notification($conn, [$student_id], 'Redemption Rejected', "Your redemption request #{$rid} has been rejected.");
            }
        }
        header('Location: ?page=star_manager');
        exit;
    }

    if ($action === 'award') {
        $stu = (int)($_POST['award_student_id'] ?? 0);
        $amt = (int)($_POST['award_amount'] ?? 0);
        $reason = trim($_POST['award_reason'] ?? '');
        if ($stu<1 || $amt<1 || $reason==='') {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Student, amount & reason required.'];
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id=?");
                $stmt->bind_param('i',$stu);
                $stmt->execute();
                $exists = $stmt->fetch();
                $stmt->close();
                if (!$exists) {
                    $ins0 = $conn->prepare("INSERT INTO stars(student_id,star_count) VALUES(?,0)");
                    $ins0->bind_param('i',$stu);
                    $ins0->execute();
                    $ins0->close();
                }

                $stmt = $conn->prepare("UPDATE stars SET star_count = star_count + ? WHERE student_id=?");
                $stmt->bind_param('ii',$amt,$stu);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO star_history(student_id,event_date,stars,reason) VALUES(?,CURDATE(),?,?)");
                $stmt->bind_param('iis',$stu,$amt,$reason);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Awarded {$amt} stars to student #{$stu}."];
                create_notification($conn, [$stu], 'Stars Awarded', "You have been awarded {$amt} stars. Reason: {$reason}");
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Error: '.$e->getMessage()];
            }
        }
        header('Location: ?page=star_manager');
        exit;
    }

    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Unknown action.'];
    header('Location: ?page=star_manager');
    exit;
}

$balances = $conn->query("SELECT s.id AS student_id, s.name AS student_name, COALESCE(st.star_count,0) AS star_count FROM students s LEFT JOIN stars st ON st.student_id=s.id ORDER BY s.name")->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT r.id, r.student_id, st.name AS student_name, r.reward_title, r.stars_required, r.requested_at FROM star_redemptions r JOIN students st ON st.id=r.student_id WHERE r.status='pending' ORDER BY r.requested_at DESC");
$stmt->execute();
$pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Admin – Star Manager</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    a, .nav-link, .sidebar a, .topbar a {
      text-decoration: none !important;
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2>⭐ Star Manager</h2>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type']==='danger'?'danger':'success' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Award Stars -->
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">Manually Award Stars</h5>
        <form method="POST" class="row g-2 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action"       value="award">

          <div class="col-md-4">
            <label class="form-label">Student</label>
            <select name="award_student_id" class="form-select form-select-sm" required>
              <option value="">— pick a student —</option>
              <?php foreach($balances as $b): ?>
                <option value="<?= $b['student_id'] ?>">
                  <?= htmlspecialchars($b['student_name']) ?> (<?= $b['star_count'] ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Stars</label>
            <input type="number" name="award_amount" min="1" class="form-control form-control-sm" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Reason</label>
            <input type="text" name="award_reason" class="form-control form-control-sm" required>
          </div>

          <div class="col-md-1">
            <button class="btn btn-sm btn-primary w-100">Award</button>
          </div>
        </form>
      </div>
    </div>

    <!-- All Balances -->
    <div class="card mb-4">
      <div class="card-body">
        <h5 class="card-title">All Student Balances</h5>
        <div class="table-responsive">
          <table class="table table-striped table-hover">
            <thead><tr><th>ID</th><th>Name</th><th>⭐ Stars</th></tr></thead>
            <tbody>
              <?php foreach($balances as $b): ?>
                <tr>
                  <td><?= $b['student_id'] ?></td>
                  <td><?= htmlspecialchars($b['student_name']) ?></td>
                  <td><?= $b['star_count'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Pending Requests -->
    <div class="card">
      <div class="card-body">
        <h5 class="card-title">Pending Redemption Requests</h5>
        <?php if (empty($pending)): ?>
          <p class="text-muted">No pending requests.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
              <thead><tr><th>Req ID</th><th>Student</th><th>Reward</th><th>Stars Req'd</th><th>Requested At</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach($pending as $r): ?>
                  <tr>
                    <td><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['student_name']) ?></td>
                    <td><?= htmlspecialchars($r['reward_title']) ?></td>
                    <td><?= $r['stars_required'] ?></td>
                    <td><?= htmlspecialchars($r['requested_at']) ?></td>
                    <td>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="redemption_id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="reward_title" value="<?= htmlspecialchars($r['reward_title'],ENT_QUOTES) ?>">
                        <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i></button>
                      </form>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="redemption_id" value="<?= $r['id'] ?>">
                        <button class="btn btn-sm btn-danger"><i class="bi bi-x-lg"></i></button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
