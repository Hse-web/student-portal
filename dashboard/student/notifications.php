<?php
// dashboard/notifications.php
// Assumes: session_start(), auth check, db connected ($conn), $studentId

$page = 'notifications';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
// 1) Mark any “mark_as_read” request
if (isset($_GET['mark_read'])) {
    $nid = (int)$_GET['mark_read'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND student_id=?");
    $stmt->bind_param('ii', $nid, $studentId);
    $stmt->execute();
    $stmt->close();
    // redirect back to avoid duplicate GET
    header('Location: ?page=notifications');
    exit();
}

// 2) Fetch all notifications (latest first)
$stmt = $conn->prepare("
    SELECT id, title, message, is_read, created_at
      FROM notifications
     WHERE student_id=?
     ORDER BY created_at DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$notifs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6b21a8">
<link rel="icon" href="/assets/icons/icon-192.png">
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
      .then(() => console.log('✅ SW registered'))
      .catch(err => console.error('⚠️ SW registration failed:', err));
  }
</script>
<div class="container-fluid">
  <h4 class="section-header">🔔 Notifications</h4>

  <?php if (empty($notifs)): ?>
    <div class="alert alert-info">You have no notifications.</div>
  <?php else: ?>
    <ul class="list-group">
      <?php foreach ($notifs as $n): ?>
        <li class="list-group-item d-flex justify-content-between align-items-start <?= $n['is_read']?'':'list-group-item-warning' ?>">
          <div class="ms-2 me-auto">
            <div class="fw-bold"><?= htmlspecialchars($n['title']) ?></div>
            <?= nl2br(htmlspecialchars($n['message'])) ?>
            <div class="text-muted small mt-1"><?= date('M j, Y H:i', strtotime($n['created_at'])) ?></div>
          </div>
          <?php if (!$n['is_read']): ?>
            <a href="?page=notifications&mark_read=<?= $n['id'] ?>" class="btn btn-sm btn-outline-primary">
              Mark as read
            </a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<style>
  .section-header { margin:2rem 0 1rem; color:#d84315; font-weight:600; }
</style>
