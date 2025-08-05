<?php
require_once __DIR__ . '/../../../config/session.php';
require_role('student');
require_once __DIR__ . '/../../../config/db.php';

header('Content-Type: application/json');
$studentId = (int)$_SESSION['student_id'];

$stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE student_id=? AND is_read=0");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($count);
$stmt->fetch();
$stmt->close();
echo json_encode(['unread_count' => $count]);
