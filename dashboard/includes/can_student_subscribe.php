<?php
// dashboard/includes/can_student_subscribe.php

function can_student_subscribe(mysqli $conn, int $studentId, int $planId): array {
    // 1) Load student flags
    $stmt = $conn->prepare("
      SELECT is_legacy
        FROM students
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $stmt->bind_result($isLegacy);
    if (!$stmt->fetch()) {
        return ['allowed'=>false,'reason'=>'Student not found'];
    }
    $stmt->close();

    // 2) Load plan details
    $stmt = $conn->prepare("
      SELECT duration_months, prorate_allowed
        FROM payment_plans
       WHERE id = ?
    ");
    $stmt->bind_param('i',$planId);
    $stmt->execute();
    $stmt->bind_result($dur,$prorateAllowed);
    if (!$stmt->fetch()) {
        return ['allowed'=>false,'reason'=>'Plan not found'];
    }
    $stmt->close();

    // 3) Prevent overlapping subscriptions
    $stmt = $conn->prepare("
      SELECT subscribed_at, plan_id
        FROM student_subscriptions
       WHERE student_id = ?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($last) {
        $end = new DateTime($last['subscribed_at']);
        $end->modify("+{$dur} months")->setDate(
          (int)$end->format('Y'), (int)$end->format('m'), 5
        );
        if (new DateTime() < $end) {
            return [
              'allowed'=>false,
              'reason'=>"Active subscription until ".$end->format('M j, Y')
            ];
        }
    }

    // 4) Mid-month rule for 1-month plans
    $today = (int)date('j');
    if ($dur===1 && !$prorateAllowed && $today > 15) {
        return [
          'allowed'=>false,
          'reason'=>"Mid-month joining not allowed for this plan"
        ];
    }

    return ['allowed'=>true];
}
