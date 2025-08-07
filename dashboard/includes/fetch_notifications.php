<?php
// dashboard/includes/fetch_notifications.php
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/db.php';    // or wherever your $conn lives
header('Content-Type: application/json');

$studentId = (int)$_SESSION['student_id'];
$stmt = $conn->prepare("
  SELECT id, title, message
    FROM notifications
   WHERE student_id = ?
     AND is_read = 0
   ORDER BY created_at DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
echo json_encode($data);
