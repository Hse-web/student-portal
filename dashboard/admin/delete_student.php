<?php
// File: dashboard/admin/delete_student.php
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

// 2) Validate student_id
$stuId = (int)($_POST['student_id'] ?? 0);
if ($stuId < 1) {
  $_SESSION['flash_error'] = 'No student specified.';
  header('Location: index.php?page=students');
  exit;
}

$conn->begin_transaction();
try {
  // 3) Fetch full before‐snapshot
  $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
  $stmt->bind_param('i',$stuId);
  $stmt->execute();
  $before = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  // 4) Delete the student (cascades will clear children)
  $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
  $stmt->bind_param('i',$stuId);
  $stmt->execute();
  $stmt->close();

  // 5) Also delete the user row
  if (!empty($before['user_id'])) {
    $stmt = $conn->prepare("
      DELETE FROM users
       WHERE id = ? AND role='student'
    ");
    $stmt->bind_param('i',$before['user_id']);
    $stmt->execute();
    $stmt->close();
  }

  $conn->commit();

  // 6) Audit log
  log_audit(
    $conn,
    $_SESSION['user_id'],
    'DELETE',
    'students',
    $stuId,
    ['before'=>$before]
  );

  $_SESSION['flash_success'] = 'Student deleted successfully.';
} catch (Throwable $e) {
  $conn->rollback();
  $_SESSION['flash_error']   = 'Error deleting student: '.$e->getMessage();
}

header('Location: index.php?page=students');
exit;
