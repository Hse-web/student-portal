<?php
// File: dashboard/admin/delete_bulk.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

$ids = array_map('intval', $_POST['student_ids'] ?? []);
if (empty($ids)) {
    $_SESSION['flash_error'] = 'No students selected.';
    header('Location: index.php?page=students');
    exit;
}

$actor = $_SESSION['user_id'];
$conn->begin_transaction();

try {
    foreach ($ids as $stuId) {
        // 1) Fetch user_id for that student
        $stmt = $conn->prepare("SELECT user_id FROM students WHERE id = ?");
        $stmt->bind_param('i', $stuId);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        // 2) Delete all child rows that have student_id
        $tables = [
            'attendance',
            'homework_assigned',
            'homework_submissions',
            'notifications',
            'progress',
            'progress_feedback',
            'stars',
            'payment_proofs',
            'payments',
            'student_subscriptions',
            'student_verifications',
        ];
        foreach ($tables as $tbl) {
            $colRes = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'student_id'");
            if ($colRes && $colRes->num_rows) {
                $conn->query("DELETE FROM `{$tbl}` WHERE student_id = {$stuId}");
            }
        }

        // 3) Delete the student record
        $conn->query("DELETE FROM `students` WHERE id = {$stuId}");

        // 4) Delete their login if role='student'
        if ($userId) {
            $stmt2 = $conn->prepare("
                DELETE FROM users
                 WHERE id = ?
                   AND role = 'student'
            ");
            $stmt2->bind_param('i', $userId);
            $stmt2->execute();
            $stmt2->close();
        }

        // 5) Audit log
        if (function_exists('log_audit')) {
            log_audit(
              $conn,
              $actor,
              'DELETE',
              'students',
              $stuId,
              ['deleted_user_id' => $userId]
            );
        }
    }

    $conn->commit();
    $_SESSION['flash_success'] = 'Selected students (and their user logins) deleted.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = 'Error deleting students: ' . $e->getMessage();
}

header('Location: index.php?page=students');
exit;
