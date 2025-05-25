<?php
// dashboard/admin/delete_student.php

require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// 1) Must be POST + valid CSRF
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
  || ! verify_csrf_token($_POST['csrf_token'] ?? null)
) {
  http_response_code(403);
  exit('Invalid request');
}

// 2) Validate the student ID
$stuId = (int)($_POST['student_id'] ?? 0);
if ($stuId < 1) {
  $_SESSION['flash_error'] = 'Invalid student.';
  header('Location: index.php?page=students');
  exit;
}

$conn->begin_transaction();
try {
  // 3) Grab the linked user_id
  $stm = $conn->prepare("SELECT user_id FROM students WHERE id = ?");
  $stm->bind_param('i', $stuId);
  $stm->execute();
  $stm->bind_result($userId);
  $stm->fetch();
  $stm->close();

  // 4) Wipe all child tables that reference student_id
  $tables = [
    'attendance',
    'homework_assigned',
    'homework_submissions',
    'notifications',
    'progress',
    'progress_feedback',
    'stars',
    'payment_proofs',
    'payments',
    'student_subscriptions',
    'student_verifications',
  ];
  foreach ($tables as $t) {
      $col = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE 'student_id'");
      if ($col && $col->num_rows) {
          $conn->query("DELETE FROM `{$t}` WHERE student_id = {$stuId}");
      }
  }

  // 5) Delete the student row
  $conn->query("DELETE FROM `students` WHERE id = {$stuId}");

  // 6) Delete their user account if it’s a student
  if (! empty($userId)) {
    $u = $conn->prepare("
      DELETE FROM users
       WHERE id = ?
         AND role = 'student'
    ");
    $u->bind_param('i', $userId);
    $u->execute();
    $u->close();
  }

  $conn->commit();
  $_SESSION['flash_success'] = 'Student deleted successfully.';
}

catch (\Throwable $e) {
  $conn->rollback();
  // you could log $e->getMessage() somewhere if you like
  $_SESSION['flash_error'] = 'Error deleting student.';
}
// Redirect back
header('Location: index.php?page=students');
exit;
