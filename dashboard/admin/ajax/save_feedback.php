<?php
// File: dashboard/admin/ajax/save_feedback.php

require_once __DIR__.'/../../../config/session.php';
require_role('admin');
require_once __DIR__.'/../../../config/db.php';

header('Content-Type: application/json');

$hwId      = $_POST['hw_id'] ?? null;
$studentId = $_POST['student_id'] ?? null;
$feedback  = trim($_POST['feedback'] ?? '');

if (!$hwId || !$studentId || $feedback === '') {
  http_response_code(400);
  echo json_encode(['status' => 'error', 'message' => 'Missing data.']);
  exit;
}

// 1. Save feedback to homework_feedback table
$stmt = $conn->prepare("
  REPLACE INTO homework_feedback (assignment_id, student_id, feedback, created_at)
  VALUES (?, ?, ?, NOW())
");
$stmt->bind_param('iis', $hwId, $studentId, $feedback);
$stmt->execute();
$stmt->close();

// 2. Add notification to the student
$title = "New Feedback on Homework";
$message = $feedback;

$stmt = $conn->prepare("
  INSERT INTO notifications (student_id, title, message, is_read, created_at)
  VALUES (?, ?, ?, 0, NOW())
");
$stmt->bind_param('iss', $studentId, $title, $message);
$stmt->execute();
$stmt->close();

echo json_encode(['status' => 'success', 'message' => 'Feedback saved and notification sent.']);
exit;
