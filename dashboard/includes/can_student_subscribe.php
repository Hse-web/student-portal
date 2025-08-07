<?php
// File: dashboard/includes/can_student_subscribe.php

/**
 * Determine whether a student may subscribe to a given plan.
 *
 * @param mysqli $conn
 * @param int    $studentId
 * @param int    $planId
 * @return array ['allowed'=>bool,'reason'=>string]
 */
function can_student_subscribe(mysqli $conn, int $studentId, int $planId): array {
    // 1) Student legacy flag
    $stmt = $conn->prepare("
      SELECT is_legacy
        FROM students
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i',$studentId);
    $stmt->execute();
    $stmt->bind_result($isLegacy);
    if (! $stmt->fetch()) {
      $stmt->close();
      return ['allowed'=>false,'reason'=>'Student not found'];
    }
    $stmt->close();

    // 2) Plan duration & proration
    $stmt = $conn->prepare("
      SELECT duration_months, prorate_allowed
        FROM payment_plans
       WHERE id = ?
    ");
    $stmt->bind_param('i',$planId);
    $stmt->execute();
    $stmt->bind_result($dur,$prorateAllowed);
    if (! $stmt->fetch()) {
      $stmt->close();
      return ['allowed'=>false,'reason'=>'Plan not found'];
    }
    $stmt->close();

    // 3) Prevent overlap
    $stmt = $conn->prepare("
      SELECT subscribed_at
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
      $end->modify("+{$dur} months");
      if (new DateTime() < $end) {
        return [
          'allowed'=>false,
          'reason'=>"Active subscription until ".$end->format('M j, Y')
        ];
      }
    }

    // 4) Mid-month rule for pure 1-month plans
    if ($dur===1 && !$prorateAllowed) {
      if ((int)date('j') > 15) {
        return [
          'allowed'=>false,
          'reason'=>"Mid-month joining not allowed for this plan"
        ];
      }
    }

    return ['allowed'=>true,'reason'=>''];
}
