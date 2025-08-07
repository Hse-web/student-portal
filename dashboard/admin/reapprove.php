<?php
// File: dashboard/admin/reapprove.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

$id       = (int)($_POST['id']       ?? 0);
$compDate = $_POST['comp_date']      ?? '';
$slot     = $_POST['slot']           ?? '';

if (!$id
 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$compDate)
 || !$slot
) {
  $_SESSION['flash_error'] = 'Invalid input.';
  header('Location: comp_requests.php');
  exit;
}

$stmt = $conn->prepare("
  UPDATE compensation_requests
     SET comp_date = ?, slot = ?, status = 'approved'
   WHERE id = ?
");
$stmt->bind_param('ssi', $compDate, $slot, $id);
$stmt->execute();
$stmt->close();

$_SESSION['flash_success'] = 'Make-up session re-scheduled.';
header('Location: comp_requests.php');
exit;
