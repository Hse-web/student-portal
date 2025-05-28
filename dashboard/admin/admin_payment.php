<?php
// File: dashboard/admin/admin_payment.php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// flash
if (!empty($_SESSION['flash_error'])) {
  echo '<div class="alert alert-danger">'.htmlspecialchars($_SESSION['flash_error']).'</div>';
  unset($_SESSION['flash_error']);
}
if (!empty($_SESSION['flash_success'])) {
  echo '<div class="alert alert-success">'.htmlspecialchars($_SESSION['flash_success']).'</div>';
  unset($_SESSION['flash_success']);
}

// fetch pending
$res = $conn->query("
  SELECT pp.id, pp.student_id, pp.file_path, pp.uploaded_at, s.name AS student_name, s.group_name
    FROM payment_proofs pp
    JOIN students s ON s.id = pp.student_id
   WHERE pp.status = 'Pending'
   ORDER BY pp.uploaded_at DESC
");
$proofs = $res->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html><!-- head… --><body>
  <div class="container py-4">
    <h2>Pending Payment Proofs</h2>
    <?php if (empty($proofs)): ?>
      <div class="alert alert-info">No pending payment proofs.</div>
    <?php else: ?>
      <table class="table">
        <thead class="table-dark">
          <tr><th>#</th><th>Student</th><th>Group</th><th>Uploaded At</th>
              <th>File</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($proofs as $pp): ?>
          <tr>
            <td><?=htmlspecialchars($pp['id'])?></td>
            <td><?=htmlspecialchars($pp['student_name'])?></td>
            <td><?=htmlspecialchars($pp['group_name'])?></td>
            <td><?=htmlspecialchars($pp['uploaded_at'])?></td>
            <td><a href="../../<?=htmlspecialchars($pp['file_path'])?>" target="_blank">View</a></td>
            <td>
              <form action="../../actions/admin_handle_proof.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(generate_csrf_token())?>">
                <input type="hidden" name="proof_id" value="<?=$pp['id']?>">
                <button class="btn btn-sm btn-success" name="action" value="approve">
                  Approve
                </button>
              </form>
              <form action="../../actions/admin_handle_proof.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?=htmlspecialchars(generate_csrf_token())?>">
                <input type="hidden" name="proof_id" value="<?=$pp['id']?>">
                <input type="text" name="reason" class="form-control form-control-sm d-inline-block w-auto" placeholder="Reason" required>
                <button class="btn btn-sm btn-danger" name="action" value="reject">
                  Reject
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif;?>
  </div>
</body>
</html>
