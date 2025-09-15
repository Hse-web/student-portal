<?php
// dashboard/includes/mark_read.php
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/db.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!empty($input['id'])) {
    $stmt = $conn->prepare("
      UPDATE notifications
         SET is_read = 1
       WHERE id = ?
         AND student_id = ?
    ");
    $nid = (int)$input['id'];
    $sid = (int)$_SESSION['student_id'];
    $stmt->bind_param('ii', $nid, $sid);
    $stmt->execute();
}
