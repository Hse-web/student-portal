<?php
// File: dashboard/admin/mark_missed.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';  // create_notification()
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: index.php?page=comp_requests');
    exit;
}

// Fetch user_id and absent_date
$stmt = $conn->prepare("
  SELECT c.user_id, DATE_FORMAT(c.absent_date,'%Y-%m-%d')
    FROM compensation_requests c
   WHERE c.id = ?
   LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$stmt->bind_result($userId, $absDate);
if (!$stmt->fetch()) {
    $stmt->close();
    header('Location: index.php?page=comp_requests');
    exit;
}
$stmt->close();

// Update to “missed”
$u = $conn->prepare("
  UPDATE compensation_requests
     SET status = 'missed'
   WHERE id = ?
     AND status <> 'missed'
");
$u->bind_param('i', $id);
$u->execute();
$u->close();

// Notify student
$msg = "Your compensation request for {$absDate} has been marked *missed*.";
create_notification(
  $conn,
  $userId,
  'Compensation Missed',
  $msg
);

header('Location: index.php?page=comp_requests');
exit;
