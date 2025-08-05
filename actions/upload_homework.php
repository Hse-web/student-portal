<?php
// File: actions/upload_homework.php

require_once __DIR__ . '/../config/bootstrap.php';
require_role('student');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../dashboard/includes/functions.php'; // ✅ Required for set_flash()

$studentId    = (int)($_SESSION['student_id'] ?? 0);
$assignmentId = (int)($_POST['assignment_id'] ?? 0);

// Validate
if (!$assignmentId || empty($_FILES['submission'])) {
    set_flash('Invalid upload request.', 'danger');
    header('Location: /artovue/dashboard/student/?page=homework');
    exit;
}

// Prepare upload dir
$uploadDir = __DIR__ . '/../uploads/homework/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

// File move
$file     = $_FILES['submission'];
$ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = "{$studentId}_{$assignmentId}_" . time() . ".{$ext}";
$target   = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $target)) {
    set_flash('Failed to move uploaded file.', 'danger');
    header('Location: /artovue/dashboard/student/?page=homework');
    exit;
}

// Save to DB
$pathForDb = "uploads/homework/{$filename}";
$stmt = $conn->prepare("
  INSERT INTO homework_submissions
    (assignment_id, student_id, file_path, submitted_at)
  VALUES (?,?,?,NOW())
  ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), submitted_at = NOW()
");
$stmt->bind_param('iis', $assignmentId, $studentId, $pathForDb);
if (!$stmt->execute()) {
    unlink($target);
    set_flash('Could not save submission: ' . $stmt->error, 'danger');
    $stmt->close();
    header('Location: /artovue/dashboard/student/?page=homework');
    exit;
}
$stmt->close();
header('Location: /artovue/dashboard/student/?page=homework');
exit;


// Store path for DB (relative path)
$pathForDb = "uploads/homework/{$filename}";

// Insert into DB
$stmt = $conn->prepare("
    INSERT INTO homework_submissions
    (assignment_id, student_id, file_path, submitted_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->bind_param('iis', $assignmentId, $studentId, $pathForDb);

if (!$stmt->execute()) {
    unlink($targetPath); // cleanup
    set_flash('Could not save submission: ' . $stmt->error, 'danger');
    $stmt->close();
    header('Location: /artovue/dashboard/student/?page=homework');
    exit;
}
$stmt->close();

set_flash('Homework submitted successfully.', 'success');
header('Location: /artovue/dashboard/student/?page=homework');
exit;
