<?php
// File: dashboard/admin/delete_student.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';

// 1) Auth guard
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../login/index.php');
    exit;
}

// 2) Which student to delete?
$stuId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($stuId < 1) {
    header('Location: index.php?page=students');
    exit;
}

// 3) Look up user_id if you also want to remove their users row later
$stmt = $conn->prepare("SELECT user_id FROM students WHERE id = ?");
$stmt->bind_param('i', $stuId);
$stmt->execute();
$stmt->bind_result($userId);
if (! $stmt->fetch()) {
    // no such student
    $stmt->close();
    header('Location: index.php?page=students');
    exit;
}
$stmt->close();

// 4) Candidate tables to clean up
$tables = [
    'attendance',
    'homework_assigned',
    'homework_submissions',
    'notifications',
    'progress',
    'progress_feedback',
    'star_history',            // may not exist
    'stars',
    'payment_proofs',
    'payments',
    'student_subscriptions',
    'student_verifications',
];

// 5) Loop and delete only when both table & column exist
foreach ($tables as $tbl) {
    // check table exists
    $tblRes = $conn->query("SHOW TABLES LIKE '{$tbl}'");
    if (! $tblRes || $tblRes->num_rows === 0) {
        continue;
    }
    // check column exists
    $colRes = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'student_id'");
    if (! $colRes || $colRes->num_rows === 0) {
        continue;
    }
    // safe to delete
    $conn->query("DELETE FROM `{$tbl}` WHERE student_id = {$stuId}");
}

// 6) Now delete the student record itself
$conn->query("DELETE FROM `students` WHERE id = {$stuId}");

// 7) Optionally delete the user record
if (! empty($userId)) {
    $conn->query("DELETE FROM `users` WHERE id = {$userId}");
}

// 8) Redirect back to the list
header('Location: index.php?page=students&msg=deleted');
exit;
