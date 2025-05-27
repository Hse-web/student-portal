<?php
// dashboard/includes/functions.php

/**
 * Compute the current amount due and next due date.
 * @return array [ totalDue:int, nextDue:string ]
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    // latest subscription
    $stmt = $conn->prepare("
      SELECT plan_id, subscribed_at
        FROM student_subscriptions
       WHERE student_id = ?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $stmt->bind_result($planId,$subAt);
    if (!$stmt->fetch()) {
      return [0,'n/a'];
    }
    $stmt->close();

    // late?
    $isLate = ((int)date('j') > 5);

    // fee break-down
    require_once __DIR__.'/fee_calculator.php';
    $fee = calculate_student_fee($conn,$studentId,$planId,false,$isLate);

    // next due = 5th after end
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
       ->setDate((int)$dt->format('Y'),(int)$dt->format('m'),5);
    $nextDue = $dt->format('M j, Y');

    return [(int)$fee['total'],$nextDue];
}
