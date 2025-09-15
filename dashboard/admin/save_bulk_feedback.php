<?php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

if (!isset($_POST['selected']) || !isset($_POST['feedback'])) {
  header("Location: index.php?page=homework_centerwise");
  exit;
}

foreach ($_POST['selected'] as $key) {
  list($sid, $aid) = explode('-', $key);
  $text = trim($_POST['feedback'][$sid][$aid] ?? '');
  if ($text !== '') {
    $stmt = $conn->prepare("
      UPDATE homework_submissions
         SET feedback = ?
       WHERE student_id = ? AND assignment_id = ?
    ");
    $stmt->bind_param('sii', $text, $sid, $aid);
    $stmt->execute();
    $stmt->close();
  }
}

header("Location: index.php?page=homework_centerwise&saved=1");
exit;
