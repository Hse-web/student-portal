<?php
// File: actions/admin_handle_proof.php

require_once __DIR__ . '/../config/session.php';
require_role('admin');
require_once __DIR__ . '/../config/db.php';

// 1) CSRF protection
if (empty($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    $_SESSION['flash_error'] = 'Invalid CSRF token.';
    header('Location: ../dashboard/admin/admin_payment.php');
    exit;
}

$proofId = (int)($_POST['proof_id'] ?? 0);
$action  = ($_POST['action'] ?? '') === 'approve' ? 'approve' : 'reject';

if ($action === 'reject') {
    // 2a) REJECTION path
    $reason = trim($_POST['reason'] ?? '');
    $stmt   = $conn->prepare("
        UPDATE payment_proofs
           SET status = 'Rejected',
               rejection_reason = ?,
               rejected_at = NOW()
         WHERE id = ?
    ");
    $stmt->bind_param('si', $reason, $proofId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['flash_success'] = 'Payment proof rejected.';
    header('Location: ../dashboard/admin/admin_payment.php');
    exit;
}

// 2b) APPROVAL path
$conn->begin_transaction();
try {
    // — 2b(i): mark proof approved
    $stmt = $conn->prepare("
        UPDATE payment_proofs
           SET status = 'Approved',
               rejection_reason = NULL,
               approved_at = NOW()
         WHERE id = ?
    ");
    $stmt->bind_param('i', $proofId);
    $stmt->execute();
    $stmt->close();

    // — 2b(ii): fetch the student_id
    $stmt = $conn->prepare("
        SELECT student_id
          FROM payment_proofs
         WHERE id = ?
    ");
    $stmt->bind_param('i', $proofId);
    $stmt->execute();
    $stmt->bind_result($studentId);
    if (! $stmt->fetch()) {
        throw new Exception("Proof #{$proofId} not found");
    }
    $stmt->close();

    // — 2b(iii): compute exactly how much they owed
    require_once __DIR__ . '/../dashboard/includes/functions.php';
    list($amountDue, $_nextDue) = compute_student_due($conn, $studentId);

    // — 2b(iv): insert a payment record
    $stmt = $conn->prepare("
        INSERT INTO payments
          (student_id, amount_paid, status, paid_at)
        VALUES (?, ?, 'Paid', NOW())
    ");
    $stmt->bind_param('id', $studentId, $amountDue);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    $_SESSION['flash_success'] = 'Payment proof approved and payment recorded.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['flash_error'] = 'Error approving proof: ' . $e->getMessage();
}

header('Location: ../dashboard/admin/admin_payment.php');
exit;
