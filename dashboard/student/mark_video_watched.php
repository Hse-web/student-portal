<?php
// File: dashboard/student/mark_video_watched.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$student_id = intval($_SESSION['student_id'] ?? 0);
$video_id   = intval($_POST['video_id'] ?? 0);
$date       = $_POST['date'] ?? '';

if ($student_id && $video_id && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  // 1) Record in video_completions
  $ins = $conn->prepare(
    "INSERT IGNORE INTO video_completions (student_id, video_id, watched_at)
     VALUES (?, ?, NOW())"
  );
  $ins->bind_param('ii', $student_id, $video_id);
  $ins->execute();
  $ins->close();

  // 2) Upsert attendance as Compensation + flag video comp
  $up = $conn->prepare(
    "INSERT INTO attendance (student_id, `date`, status, is_video_comp)
     VALUES (?, ?, 'Compensation', 1)
     ON DUPLICATE KEY UPDATE status='Compensation', is_video_comp=1"
  );
  $up->bind_param('is', $student_id, $date);
  $up->execute();
  $up->close();

  $_SESSION['flash_success'] = "Marked watched & attendance updated!";
} else {
  $_SESSION['flash_error'] = "Invalid request.";
}

header("Location: index.php?page=video_compensation&date=" . urlencode($date));
exit;

// 2) On first completion, award badge #1 (First Compensation)
if ($inserted) {
    // check they’ve never had it
    $chk = $conn->prepare("SELECT 1 FROM user_badges WHERE student_id=? AND badge_id=1");
    $chk->bind_param('i', $student_id);
    $chk->execute();
    $chk->store_result();
    if ($chk->num_rows === 0) {
        // award
        $ins = $conn->prepare("
          INSERT INTO user_badges (student_id, badge_id)
          VALUES (?, 1)
        ");
        $ins->bind_param('i', $student_id);
        $ins->execute();
        $ins->close();

        // fetch badge info
        $b = $conn->prepare("SELECT label, description FROM badges WHERE id=1");
        $b->execute();
        $b->bind_result($bLabel, $bDesc);
        $b->fetch();
        $b->close();

        $_SESSION['badge_earned'] = [
          'label'       => $bLabel,
          'description' => $bDesc
        ];
    }
    $chk->close();
}

$_SESSION['flash_success'] = "Marked as watched!";
header("Location: index.php?page=video_compensation&date=" . urlencode($date));
exit;

?>
