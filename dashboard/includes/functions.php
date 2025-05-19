<?php
// File: dashboard/includes/functions.php

/**
 * Compute the current amount due and next‐due date for a student.
 * Returns [ totalDue (int), nextDue (string) ].
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    // 1) Latest subscription + student flags
    $stmt = $conn->prepare("
      SELECT p.duration_months, p.amount, s.subscribed_at,
             st.is_legacy, st.centre_id
        FROM student_subscriptions AS s
        JOIN payment_plans          AS p ON p.id        = s.plan_id
        JOIN students               AS st ON st.id     = s.student_id
       WHERE s.student_id = ?
       ORDER BY s.subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($dur, $planAmt, $subAt, $isLegacy, $centreId);
    if (!$stmt->fetch()) {
        // no subscription → nothing due
        return [0, 'n/a'];
    }
    $stmt->close();

    // 2) Centre fee settings
    $stmt = $conn->prepare("
      SELECT enrollment_fee, advance_fee, prorate_allowed, gst_percent
        FROM center_fee_settings
       WHERE centre_id = ?
    ");
    $stmt->bind_param('i', $centreId);
    $stmt->execute();
    $stmt->bind_result($enrollFee, $advFee, $prorateAllowed, $gstPct);
    $stmt->fetch();
    $stmt->close();

    // 3) First‐time check
    $stmt = $conn->prepare("
      SELECT COUNT(*) FROM student_subscriptions WHERE student_id = ?
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($subsCount);
    $stmt->fetch();
    $stmt->close();
    $isFirst = ($subsCount <= 1);

    // 4) Tuition
    if ($isLegacy) {
        // legacy students pay per‐month
        $tuition = $dur > 0 ? $planAmt / $dur : 0;
    } else {
        // new / non-legacy pay full plan
        $tuition = $planAmt;
    }

    // 5) Proration (1-month only)
    if (!$isLegacy && $dur === 1 && $prorateAllowed) {
        $day = (int)(new DateTime($subAt))->format('j');
        if ($day > 15) {
            $tuition *= 0.5;
        }
    }

    // 6) One-time (enrollment + advance) only on first non-legacy
    $oneTime = 0;
    if (!$isLegacy && $isFirst) {
        $oneTime = $enrollFee + $advFee;
    }

    // 7) Totals (rounded to nearest rupee!)
    $subtotal = round($tuition + $oneTime);
    $gstAmt   = round($subtotal * ($gstPct / 100));
    $totalDue = round($subtotal + $gstAmt);

    // 8) Next‐due date = 5th of (subAt + duration months)
    $d = new DateTime($subAt);
    $d->modify("+{$dur} months")
      ->setDate((int)$d->format('Y'), (int)$d->format('m'), 5);
    $nextDue = $d->format('M j, Y');

    return [$totalDue, $nextDue];
}
