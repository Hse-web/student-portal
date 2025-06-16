<?php
// File: dashboard/student/redeem.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// 1) Must be POST + valid CSRF + correct fields
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

// 2) CSRF check
if (empty($_POST['csrf_token']) || ! verify_csrf_token($_POST['csrf_token'])) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid session (CSRF). Please try again.'];
  header('Location: stars.php');
  exit;
}

$student_id    = (int)($_SESSION['student_id'] ?? 0);
$reward_title  = trim($_POST['reward_title'] ?? '');
$stars_required = (int)($_POST['stars_required'] ?? 0);

if ($student_id < 1 || $reward_title === '' || $stars_required < 1) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid redemption request.'];
  header('Location: stars.php');
  exit;
}

// 3) Re‐fetch current star balance to ensure they still have enough
$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($currentBalance);
$stmt->fetch();
$stmt->close();

if ($currentBalance === null) {
  // If there's no row in `stars`, treat that as zero balance
  $currentBalance = 0;
}

if ($currentBalance < $stars_required) {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'You do not have enough stars to redeem.'];
  header('Location: stars.php');
  exit;
}

// 4) Insert a new pending redemption request
$ins = $conn->prepare("
  INSERT INTO star_redemptions
    (student_id, reward_title, stars_required, status)
  VALUES (?, ?, ?, 'pending')
");
$ins->bind_param('isi', $student_id, $reward_title, $stars_required);
$success = $ins->execute();
$ins->close();

if ($success) {
  $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Redemption request submitted! Await approval.'];
} else {
  $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Database error. Please try again later.'];
}

// 5) Redirect back to the stars page
header('Location: stars.php');
exit;
