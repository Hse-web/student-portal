<?php
ob_start();
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

$studentId = $_POST['student_id'] ?? null;
$assignmentId = $_POST['assignment_id'] ?? null;

if (!$studentId || !$assignmentId) {
  header("Location: homework_centerwise.php?error=missing_params");
  exit;
}

// Check if already awarded
$check = $conn->prepare("SELECT id FROM homework_rewards WHERE student_id = ? AND assignment_id = ?");
$check->bind_param('ii', $studentId, $assignmentId);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
  $stmt = $conn->prepare("INSERT INTO homework_rewards (student_id, assignment_id, stars) VALUES (?, ?, 1)");
  $stmt->bind_param('ii', $studentId, $assignmentId);
  $stmt->execute();
}
$check->close();
header("Location: homework_centerwise.php?success=star_added");
exit;
