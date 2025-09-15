<?php
// dashboard/includes/payment_functions.php

/**
 * Get current payment status for a student
 */
function getCurrentPaymentStatus($conn, $student_id, $due_date) {
    $stmt = $conn->prepare("
        SELECT p.status, pp.status as proof_status, pp.uploaded_at
        FROM payments p
        LEFT JOIN payment_proofs pp ON p.payment_id = pp.payment_id
        WHERE p.student_id = ? AND p.due_date = ?
        ORDER BY pp.uploaded_at DESC
        LIMIT 1
    ");
    $stmt->bind_param('is', $student_id, $due_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result) {
        return 'due'; // No payment record exists
    }
    
    $paymentStatus = $result['status'];
    $proofStatus = $result['proof_status'];
    
    if ($paymentStatus === 'Paid' && $proofStatus === 'Approved') {
        return 'paid';
    } elseif ($proofStatus === 'Rejected') {
        return 'rejected';
    } elseif ($proofStatus === 'Pending') {
        return 'pending_review';
    } else {
        return 'due';
    }
}

/**
 * Add here any common payment helper functions as needed.
 */
?>
