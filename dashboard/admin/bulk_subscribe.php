<?php
// File: dashboard/admin/bulk_subscribe.php
ob_start();
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// 1) CSRF protection
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
  || ! verify_csrf_token($_POST['csrf_token'] ?? null)
) {
    $_SESSION['flash_error'] = 'Invalid session. Please try again.';
    header('Location: students.php');
    exit;
}

// 2) Validate inputs
if (empty($_POST['student_ids']) || !is_array($_POST['student_ids'])) {
    $_SESSION['flash_error'] = 'No students selected.';
    header('Location: students.php');
    exit;
}
$planId = (int)($_POST['plan_id'] ?? 0);
if ($planId < 1) {
    $_SESSION['flash_error'] = 'Please select a plan.';
    header('Location: students.php');
    exit;
}

$studentIds = array_map('intval', $_POST['student_ids']);

$conn->begin_transaction();
try {
    // Prepare subscription insert
    $insSub = $conn->prepare("
      INSERT INTO student_subscriptions
        (student_id, plan_id, subscribed_at)
      VALUES (?, ?, NOW())
    ");

    // Prepare payment calculation lookup
    $calcStmt = $conn->prepare("
      SELECT 
        p.amount      AS plan_amt,
        cfs.gst_percent
      FROM payment_plans p
      JOIN centre_fee_settings cfs 
        ON cfs.centre_id = (
          SELECT centre_id FROM students WHERE id = ?
        )
      WHERE p.id = ?
      LIMIT 1
    ");

    // Prepare payment insert
    $insPay = $conn->prepare("
      INSERT INTO payments
        (student_id, status, amount_paid, amount_due)
      VALUES (?, 'Pending', 0.00, ?)
    ");

    foreach ($studentIds as $sid) {
        // 3) Insert subscription
        $insSub->bind_param('ii', $sid, $planId);
        $insSub->execute();

        // 4) Compute due amount
        $calcStmt->bind_param('ii', $sid, $planId);
        $calcStmt->execute();
        $calcStmt->bind_result($planAmt, $gstPct);
        if ($calcStmt->fetch()) {
            $subtotal = $planAmt;
            $gstAmt   = round($subtotal * ($gstPct/100), 2);
            $due      = round($subtotal + $gstAmt, 2);

            // 5) Insert payment
            $insPay->bind_param('id', $sid, $due);
            $insPay->execute();
        }
        $calcStmt->free_result();
    }

    // Clean up
    $insSub->close();
    $calcStmt->close();
    $insPay->close();

    $conn->commit();
    $_SESSION['flash_success'] = 'Plan applied to selected students.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = 'Error applying plan: ' . $e->getMessage();
}

header('Location: students.php');
exit;
