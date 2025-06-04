<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
if (!isset($_GET['id'])) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'msg'  => 'Invalid request.'
    ];
    header('Location: index.php?page=comp_requests');
    exit;
}
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

$_SESSION['flash'] = [
    'type' => 'warning',
    'msg'  => 'Compensation request marked as missed.'
];

header('Location: index.php?page=comp_requests');
exit;
