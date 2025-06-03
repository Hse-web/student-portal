<?php
require_once __DIR__ . '/../config/session.php';

// 1) Only logged‑in students can upload
if (
  empty($_SESSION['logged_in']) ||
  $_SESSION['logged_in'] !== true ||
  ($_SESSION['role'] ?? '') !== 'student'
) {
  header('Location: ../login/index.php');
  exit();
}

// 2) DB connection
require __DIR__ . '/../config/db.php';

// 3) Gather inputs
$studentId    = (int) $_SESSION['student_id'];
$assignmentId = isset($_POST['assignment_id'])
              ? (int) $_POST['assignment_id']
              : 0;

// 4) Validate
if (! $assignmentId || ! isset($_FILES['submission'])) {
  die("❌ Invalid request.");
}

// 5) Prepare upload directory
$uploadDir = __DIR__ . '/../uploads/homework/';
if (! is_dir($uploadDir)) {
  mkdir($uploadDir, 0755, true);
}

// 6) Move the file
$file      = $_FILES['submission'];
$ext       = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename  = "{$studentId}_{$assignmentId}_" . time() . ".{$ext}";
$target    = $uploadDir . $filename;

if (! move_uploaded_file($file['tmp_name'], $target)) {
  die("❌ Failed to move uploaded file.");
}

// 7) Record in DB
$pathForDb = "../uploads/homework/{$filename}";  // relative to project root or public
$stmt = $conn->prepare("
  INSERT INTO homework_submissions
    (assignment_id, student_id, file_path, submitted_at)
  VALUES (?,?,?,NOW())
");
$stmt->bind_param('iis', $assignmentId, $studentId, $pathForDb);
if (! $stmt->execute()) {
  // if DB insert fails, optionally delete the moved file
  unlink($target);
  die("❌ Could not save submission: " . $stmt->error);
}
$stmt->close();

// 8) Redirect back
header("Location: ../dashboard/student.php?page=homework");
exit();
