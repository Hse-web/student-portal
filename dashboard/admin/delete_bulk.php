<?php
// File: dashboard/admin/delete_bulk.php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

$ids = array_map('intval', $_POST['student_ids'] ?? []);
if (empty($ids)) {
    $_SESSION['flash_error'] = 'No students selected.';
    header('Location:index.php?page=students');
    exit;
}

$actor = $_SESSION['user_id'];
$conn->begin_transaction();

try {
    foreach ($ids as $stuId) {
        // 1) Fetch the student’s user_id (so we can delete that login too)
        $stmt = $conn->prepare("SELECT user_id FROM students WHERE id = ?");
        $stmt->bind_param('i', $stuId);
        $stmt->execute();
        $stmt->bind_result($userId);
        $stmt->fetch();
        $stmt->close();

        // 2) Delete all child‐rows in tables that actually have student_id
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
            // only delete if this table has a student_id column
            $colRes = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'student_id'");
            if ($colRes && $colRes->num_rows) {
                $conn->query("DELETE FROM `{$tbl}` WHERE student_id = {$stuId}");
            }
        }

        // 3) Delete the student row itself
        $conn->query("DELETE FROM `students` WHERE id = {$stuId}");

        // 4) Now delete their login (only if they were a student)
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

        // 5) Audit log: what the student record looked like before deletion?
        //    (optional; remove if you don’t yet have audit_logs)
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

header('Location:index.php?page=students');
exit;
