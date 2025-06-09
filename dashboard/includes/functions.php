<?php
// File: dashboard/includes/functions.php

// Ensure calculate_student_fee() is already loaded
require_once __DIR__ . '/fee_calculator.php';

/**
 * Compute the current amount due and next due date for a student.
 * 
 * We treat the student as “new” if they have not yet paid any invoice.
 *
 * @param mysqli $conn
 * @param int    $studentId
 * @return array [ totalDue (float), nextDue (string) ]
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    // ─── 1) Check if the student has any payment with status='Paid' ─────
    $stmtA = $conn->prepare("
      SELECT COUNT(*) AS cnt
        FROM payments
       WHERE student_id = ?
         AND status = 'Paid'
    ");
    $stmtA->bind_param('i', $studentId);
    $stmtA->execute();
    $stmtA->bind_result($paidCount);
    $stmtA->fetch();
    $stmtA->close();

    // If the student has never paid, treat them as “new”:
    $isNew = ($paidCount === 0);

    // ─── 2) If the student has never subscribed at all, we return zero due ─
    // Actually, since we insert a payment row (even Pending) at student creation,
    // they will have at least one payment record (status='Pending') but not 'Paid'.
    // We still consider them “new” until they have a 'Paid' record.
    // To compute “due,” however, we still need their latest subscription row
    // (which we inserted at creation time).
    // So we fetch the latest subscription below.

    // ─── 3) Fetch the student’s most recent subscription (plan_id + subscribed_at)
    $stmtB = $conn->prepare("
      SELECT plan_id, subscribed_at
        FROM student_subscriptions
       WHERE student_id = ?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $stmtB->bind_param('i', $studentId);
    $stmtB->execute();
    $stmtB->bind_result($planId, $subscribedAt);
    if (! $stmtB->fetch()) {
        // If somehow there’s no subscription row yet, return zero due.
        $stmtB->close();
        return [0.0, 'n/a'];
    }
    $stmtB->close();

    // ─── 4) Determine if they are late (past 5th of the month) ─────────
    $isLate = ((int) date('j') > 5);

    // ─── 5) Calculate fee breakdown, passing $isNew so that one‐time fees apply
    $fee = calculate_student_fee(
        $conn,
        $studentId,
        $planId,
        /* isNewStudent = */ $isNew,
        $isLate
    );

    // ─── 6) Compute “next due date” = 5th after end of current plan ─────
    $stmtC = $conn->prepare("
      SELECT duration_months
        FROM payment_plans
       WHERE id = ?
    ");
    $stmtC->bind_param('i', $planId);
    $stmtC->execute();
    $stmtC->bind_result($durationMonths);
    $stmtC->fetch();
    $stmtC->close();

    // Start from the subscription’s “subscribed_at” date:
    $dt = new DateTime($subscribedAt);
    // Add plan duration (in months), then set the day to 5th
    $dt->modify("+{$durationMonths} months")
       ->setDate((int)$dt->format('Y'), (int)$dt->format('m'), 5);
    $nextDue = $dt->format('M j, Y');

    return [(float)$fee['total'], $nextDue];
}
