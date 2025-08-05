<?php
$page = 'check_notifications';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
header('Content-Type: application/json');

if (!isset($_SESSION['student_id'])) {
  echo json_encode(['unread_count' => 0]);
  exit;
}

$studentId = (int)$_SESSION['student_id'];

$stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE student_id=? AND is_read=0");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();

echo json_encode(['unread_count' => $count]);
