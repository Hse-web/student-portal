<?php
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
require_role('admin');

if (empty($_POST['student_ids']) || !is_array($_POST['student_ids'])) {
    $_SESSION['flash_error'] = 'No students selected for deletion.';
    header('Location: index.php?page=students');
    exit;
}

$ids = array_map('intval', $_POST['student_ids']);

$conn->begin_transaction();
try {
    $tables = [
        'attendance','homework_assigned','homework_submissions',
        'notifications','progress','progress_feedback',
        'star_history','stars','payment_proofs','payments',
        'student_subscriptions','student_verifications',
    ];

    foreach ($ids as $stuId) {
        foreach ($tables as $tbl) {
            $exists = $conn->query("SHOW TABLES LIKE '{$tbl}'");
            if ($exists && $exists->num_rows) {
                $col = $conn->query("SHOW COLUMNS FROM `{$tbl}` LIKE 'student_id'");
                if ($col && $col->num_rows) {
                    $conn->query("DELETE FROM `{$tbl}` WHERE student_id = {$stuId}");
                }
            }
        }
        $conn->query("DELETE FROM `students` WHERE id = {$stuId}");
    }

    $conn->commit();
    $_SESSION['flash_success'] = 'Selected students deleted successfully.';
} catch (\Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = 'Error deleting selected students.';
}

header('Location: index.php?page=students');
exit;
