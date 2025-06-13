<?php
// File: dashboard/includes/functions.php

require_once __DIR__ . '/fee_calculator.php';

/**
 * Compute the current amount due and next due date for a student.
 * @return array [ totalDue (float), nextDueLabel (string), nextDueISO (string) ]
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    // 1) Have they ever paid?
    $stmt = $conn->prepare("
      SELECT COUNT(*) 
        FROM payments 
       WHERE student_id = ? 
         AND status     = 'Paid'
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $stmt->bind_result($paidCount);
    $stmt->fetch();
    $stmt->close();
    $isNew = ($paidCount === 0);

    // 2) Latest subscription
    $stmt = $conn->prepare("
      SELECT plan_id, subscribed_at
        FROM student_subscriptions
       WHERE student_id = ?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $stmt->bind_result($planId,$subscribedAt);
    if (!$stmt->fetch()) {
      $stmt->close();
      return [0.0,'n/a','1970-01-01'];
    }
    $stmt->close();

    // 3) Are we late? (only on renewals, and only after the 5th)
    $day    = (int)date('j');
    $isLate = (! $isNew && $day > 5);

    // 4) Calculate breakdown
    $fee = calculate_student_fee(
      $conn,
      $studentId,
      $planId,
      $isNew,
      $isLate
    );

    // 5) Compute next due date = 5th after the plan ends
    $stmt = $conn->prepare("
      SELECT duration_months
        FROM payment_plans
       WHERE id = ?
    ");
    $stmt->bind_param('i',$planId);
    $stmt->execute();
    $stmt->bind_result($durationMonths);
    $stmt->fetch();
    $stmt->close();

    $dt = new DateTime($subscribedAt);
    $dt->modify("+{$durationMonths} months")
       ->setDate((int)$dt->format('Y'), (int)$dt->format('m'), 5);
    $nextDueISO   = $dt->format('Y-m-d');
    $nextDueLabel = $dt->format('M j, Y');

    return [
      (float)$fee['total'],
      $nextDueLabel,
      $nextDueISO
    ];
}
