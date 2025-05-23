<?php
// dashboard/includes/functions.php

/**
 * Compute the current amount due and next due date.
 * Returns: [ totalDue:int, nextDue:string ]
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    // 1) Latest subscription
    $stmt = $conn->prepare("
      SELECT s.plan_id, s.subscribed_at,
             st.is_legacy
        FROM student_subscriptions s
        JOIN students            st ON st.id = s.student_id
       WHERE s.student_id = ?
       ORDER BY s.subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $stmt->bind_result($planId,$subAt,$isLegacy);
    if (!$stmt->fetch()) {
        return [0,'n/a'];
    }
    $stmt->close();

    // 2) Use fee_calculator for current due
    require_once __DIR__ . '/fee_calculator.php';
    // isNewStudent = false because this is a renewal
    // isLate = date > 5
    $isLate = ((int)date('j') > 5);
    $fee = calculate_student_fee($conn,$studentId,$planId,false,$isLate);

    // 3) Compute next due date = 5th after plan end
    // get plan duration
    $p = $conn->prepare("
      SELECT duration_months
        FROM payment_plans
       WHERE id = ?
    ");
    $p->bind_param('i',$planId);
    $p->execute();
    $p->bind_result($duration);
    $p->fetch();
    $p->close();

    $dt = new DateTime($subAt);
    $dt->modify("+{$duration} months")
       ->setDate((int)$dt->format('Y'),
                 (int)$dt->format('m'),
                 5
       );
    $nextDue = $dt->format('M j, Y');

    return [ (int)$fee['total'], $nextDue ];
}
