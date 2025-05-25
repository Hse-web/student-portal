<?php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_GET['id'])) exit("Invalid request");
$id = intval($_GET['id']);

// 1) Update request status
$u = $conn->prepare("
  UPDATE compensation_requests
     SET status='missed'
   WHERE id=?
");
$u->bind_param('i',$id);
$u->execute();
$u->close();

// 2) Remove its attendance record
$d = $conn->prepare("
  DELETE FROM attendance
   WHERE compensation_request_id=?
");
$d->bind_param('i',$id);
$d->execute();
$d->close();

header("Location: comp_requests.php?updated=1");
exit;
