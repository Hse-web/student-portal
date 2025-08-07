<?php
// File: dashboard/student/redeem_history.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
$student_id = (int)($_SESSION['student_id'] ?? 0);
if ($student_id < 1) {
    header('Location: ../../login.php');
    exit;
}

// 1) Fetch all redemptions for this student (any status)
$stmt = $conn->prepare("
  SELECT id, reward_title, stars_required, status, requested_at, processed_at
    FROM star_redemptions
   WHERE student_id = ?
   ORDER BY requested_at DESC
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2) Render student header (opens HTML & sidebar)
$page = 'stars';
render_student_header('My Redemption History');
?>

<div class="container-fluid p-4">
  <h4 class="mb-4">My Redemption History</h4>

  <?php if (empty($history)): ?>
    <p class="text-muted">You have no redemption requests yet.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-striped table-bordered">
        <thead class="table-light">
          <tr>
            <th># Req ID</th>
            <th>Reward</th>
            <th>Stars Reserved</th>
            <th>Status</th>
            <th>Requested At</th>
            <th>Processed At</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= (int)$h['id'] ?></td>
              <td><?= htmlspecialchars($h['reward_title']) ?></td>
              <td><?= (int)$h['stars_required'] ?></td>
              <td>
                <?php if ($h['status'] === 'pending'): ?>
                  <span class="badge bg-warning text-dark">Pending</span>
                <?php elseif ($h['status'] === 'approved'): ?>
                  <span class="badge bg-success">Approved</span>
                <?php else: ?>
                  <span class="badge bg-danger">Rejected</span>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($h['requested_at']) ?></td>
              <td><?= $h['processed_at'] ? htmlspecialchars($h['processed_at']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
// close HTML via student footer
render_student_footer();
