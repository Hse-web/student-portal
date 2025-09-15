<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../dashboard/includes/functions.php';

// Safety: run for all active students only
$students = $conn->query("SELECT id FROM students WHERE status IS NULL OR status!='Inactive'");

$insertStmt = $conn->prepare("
  INSERT INTO payments (student_id, status, amount_paid, amount_due, due_date, created_at)
  SELECT ?, 'Pending', 0, 0, ?, NOW()
  FROM DUAL
  WHERE NOT EXISTS (
    SELECT 1 FROM payments
    WHERE student_id = ?
      AND (
        (due_date = ?)
        OR (paid_at >= DATE_SUB(?, INTERVAL 7 DAY) AND paid_at <= DATE_ADD(?, INTERVAL 7 DAY))
      )
  )
");
if (!$insertStmt) {
  die("Prepare failed: " . $conn->error);
}

$created = 0;
while ($row = $students->fetch_assoc()) {
  $sid = (int)$row['id'];

  // Use your existing logic to compute the **true** next due
  $due = compute_student_due($conn, $sid);
  $dueIso = $due['due_date'] ?? null;

  // Skip if we couldn't compute
  if (!$dueIso || $dueIso === '0000-00-00') continue;

  // Create the row ONLY if it doesn't already exist
  $insertStmt->bind_param('isisss', $sid, $dueIso, $sid, $dueIso, $dueIso, $dueIso);
  if ($insertStmt->execute() && $insertStmt->affected_rows > 0) {
    $created++;
  }
}

echo "Created/ensured payment rows for next cycles: $created\n";
