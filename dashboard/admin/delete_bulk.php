<?php
// File: dashboard/admin/delete_bulk.php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1) Must be POST + valid CSRF
if ($_SERVER['REQUEST_METHOD']!=='POST'
  || ! verify_csrf_token($_POST['csrf_token'] ?? null)
) {
  http_response_code(403);
  exit('Invalid request');
}

// 2) Collect IDs
$ids = array_map('intval', $_POST['student_ids'] ?? []);
if (empty($ids)) {
  $_SESSION['flash_error'] = 'No students selected for deletion.';
  header('Location: index.php?page=students');
  exit;
}

// 3) Fetch before‐snapshots
$in = implode(',', array_fill(0, count($ids), '?'));
$sql = "SELECT * FROM students WHERE id IN ($in)";
$stmt = $conn->prepare($sql);
$stmt->bind_param(str_repeat('i',count($ids)), ...$ids);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->begin_transaction();
try {
  // 4) Bulk delete
  $stmt = $conn->prepare("DELETE FROM students WHERE id IN ($in)");
  $stmt->bind_param(str_repeat('i',count($ids)), ...$ids);
  $stmt->execute();
  $stmt->close();

  $conn->commit();

  // 5) Audit each deletion
  foreach ($rows as $r) {
    log_audit(
      $conn,
      $_SESSION['user_id'],
      'DELETE',
      'students',
      (int)$r['id'],
      ['before'=>$r]
    );
  }

  $_SESSION['flash_success'] = 'Selected students deleted successfully.';
} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_error'] = 'Error deleting selected students: '.$e->getMessage();
}

header('Location: index.php?page=students');
exit;
