<?php
require_once __DIR__.'/../../config/session.php';
require_role('admin');
require_once __DIR__.'/../../config/db.php';

// fetch all requests (keep comp_date even if “missed”)
$stmt = $conn->prepare("
  SELECT
    c.id,
    IFNULL(DATE_FORMAT(c.comp_date,'%Y-%m-%d'), '-')
      AS comp_date,
    s.name AS student_name,
    DATE_FORMAT(c.requested_at,'%Y-%m-%d %h:%i %p') AS requested_at,
    c.status
  FROM compensation_requests c
  JOIN students s ON s.user_id=c.user_id
  ORDER BY c.comp_date DESC, c.requested_at DESC
");
$stmt->execute();
$res = $stmt->get_result();
?>


<main class="container-fluid px-4">
  <h1 class="mt-4">Compensation Requests</h1>
  <?php if(isset($_GET['updated'])):?>
    <div class="alert alert-success my-3">
      Status updated.
    </div>
  <?php endif;?>

  <div class="card shadow-sm mb-4">
    <div class="card-body table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-dark">
          <tr>
            <th>ID</th>
            <th>Date</th>
            <th>Student</th>
            <th>Requested At</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php while($r=$res->fetch_assoc()):?>
          <tr>
            <td><?=htmlspecialchars($r['id'])?></td>
            <td><?=htmlspecialchars($r['comp_date'])?></td>
            <td><?=htmlspecialchars($r['student_name'])?></td>
            <td><?=htmlspecialchars($r['requested_at'])?></td>
            <td>
              <?php if($r['status']==='approved'):?>
                <span class="badge bg-success">Approved</span>
              <?php else:?>
                <span class="badge bg-warning text-dark">Missed</span>
              <?php endif;?>
            </td>
            <td>
              <?php if($r['status']==='approved'):?>
                <a href="mark_missed.php?id=<?=$r['id']?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Mark missed?');">
                  Mark Missed
                </a>
              <?php else:?>
                <button class="btn btn-sm btn-secondary" disabled>—</button>
              <?php endif;?>
            </td>
          </tr>
        <?php endwhile;?>
        </tbody>
      </table>
    </div>
  </div>
</main>

<?php include __DIR__.'/../../templates/partials/footer.php'; ?>
