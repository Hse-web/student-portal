<?php
require_once __DIR__.'/../../config/session.php';
require_role('admin');
require_once __DIR__.'/../../config/db.php';
$res = $conn->query(
  "SELECT vc.id,s.name AS student,v.class_date,vc.watched_at
     FROM video_completions vc
     JOIN students s ON s.id=vc.student_id
     JOIN compensation_videos v ON v.id=vc.video_id
    ORDER BY vc.watched_at DESC"
);

?>
<main class="container-fluid px-4">
  <h1 class="mt-4">Video Completions</h1>
  <div class="card mb-4 shadow-sm">
    <div class="card-body table-responsive">
      <table class="table table-striped">
        <thead class="table-dark"><tr>
          <th>ID</th><th>Student</th><th>Class Date</th><th>Watched At</th>
        </tr></thead><tbody>
        <?php while($r=$res->fetch_assoc()): ?>
          <tr>
            <td><?=htmlspecialchars($r['id'])?></td>
            <td><?=htmlspecialchars($r['student'])?></td>
            <td><?=htmlspecialchars($r['class_date'])?></td>
            <td><?=htmlspecialchars($r['watched_at'])?></td>
          </tr>
        <?php endwhile;?>
        </tbody>
      </table>
    </div>
  </div>
</main>
<?php include __DIR__.'/../../templates/partials/footer.php'; ?>