<?php
// File: dashboard/includes/functions.php
// Canonical billing helpers (no “hold” logic here).

require_once __DIR__ . '/fee_calculator.php';

/* ---------- Date helpers (avoid DateTime(null) deprecations) ---------- */

function is_zero_date(?string $s): bool {
    if ($s === null) return true;
    $s = trim($s);
    return $s === '' || $s === '0000-00-00' || $s === '0000-00-00 00:00:00';
}

function safe_dt(?string $s, DateTimeZone $tz, string $fallback = 'now'): DateTime {
    return new DateTime(is_zero_date($s) ? $fallback : $s, $tz);
}

/** First cycle due from subscription start: (+duration) then normalize to the 5th. */
function first_cycle_from_sub(DateTime $subscribedAt, int $durationMonths): DateTime {
    $d = clone $subscribedAt;
    $d->modify("+{$durationMonths} months");
    $d->setDate((int)$d->format('Y'), (int)$d->format('m'), 5)->setTime(0,0,0);
    return $d;
}

/**
 * compute_student_due()
 *
 * Returns:
 * [ totalDue(float),
 *   nextDueLabel(string),  // "Sep 5, 2025"
 *   nextDueISO(string),    // "2025-09-05"
 *   fee(array),
 *   ctx(array: isNew,isLate,plan_id,plan_name,duration_months)
 * ]
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    $tz    = new DateTimeZone('Asia/Kolkata');
    $today = new DateTime('now', $tz);

    // ── Latest subscription + plan
    $stmt = $conn->prepare("
      SELECT s.plan_id, s.subscribed_at, p.duration_months
        FROM student_subscriptions s
        JOIN payment_plans p ON p.id = s.plan_id
       WHERE s.student_id = ?
       ORDER BY s.subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($planId, $subscribedAtStr, $durationMonths);
    if (!$stmt->fetch()) {
        $stmt->close();
        return [0.0, '—', '1970-01-01', [], [
            'isNew'           => true,
            'isLate'          => false,
            'cycle_settled'   => false,
            'anchor_due_iso'  => null,
            'plan_id'         => null,
            'duration_months' => 0,
        ]];
    }
    $stmt->close();

    $subscribedAt = safe_dt($subscribedAtStr, $tz);

    // Have they ever paid?
    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE student_id = ? AND status = 'Paid'");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($paidCount);
    $stmt->fetch();
    $stmt->close();
    $isNew = ($paidCount === 0);

    // ── Latest payments row (most recent ANCHOR MONTH — first of that month)
    // IMPORTANT: requires payments.anchor_month DATE GENERATED or maintained by app.
    $stmt = $conn->prepare("
      SELECT payment_id, status, due_date, anchor_month
        FROM payments
       WHERE student_id = ?
       ORDER BY anchor_month DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $latestPay = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cycleSettled = false;
    $anchorISO    = null; // canonical cycle anchor (YYYY-MM-01)

    if ($latestPay) {
        // Prefer anchor_month; fall back to due_date if anchor wasn’t populated
        $anchorISO = $latestPay['anchor_month'] ?: $latestPay['due_date'];

        // Latest proof status for that payment (to treat as settled if Approved)
        $stmt = $conn->prepare("
          SELECT status
            FROM payment_proofs
           WHERE payment_id = ?
           ORDER BY uploaded_at DESC
           LIMIT 1
        ");
        $stmt->bind_param('i', $latestPay['payment_id']);
        $stmt->execute();
        $stmt->bind_result($proofStatus);
        $stmt->fetch();
        $stmt->close();

        $cycleSettled = ($latestPay['status'] === 'Paid') || ($proofStatus === 'Approved');
    }

    // ── Decide which due date we’re talking about (drive off ANCHOR)
    if (!$latestPay) {
        // First cycle: subscribed_at + duration → normalized to 5th
        $due = first_cycle_from_sub($subscribedAt, (int)$durationMonths);
    } else {
        // Base anchor = anchor_month (YYYY-MM-01) or first cycle if missing
        $base = is_zero_date($anchorISO)
          ? first_cycle_from_sub($subscribedAt, (int)$durationMonths)
          : safe_dt($anchorISO, $tz);

        if ($cycleSettled) {
            // Advance one plan duration from the last settled anchor
            $due = clone $base;
            $due->modify("+{$durationMonths} months");
            $due->setDate((int)$due->format('Y'), (int)$due->format('m'), 5);
        } else {
            // Stay on the current open cycle (normalize to 5th if needed)
            $due = clone $base;
            $due->setDate((int)$due->format('Y'), (int)$due->format('m'), 5);
        }
    }

    // Guard impossible years
    if ((int)$due->format('Y') < 1971) {
        $due = (clone $today)->modify('first day of next month');
        $due->setDate((int)$due->format('Y'), (int)$due->format('m'), 5);
    }

    // Late only for open cycles, after due, and not first-ever
    $isLate = (!$cycleSettled && !$isNew && ($today > (clone $due)->setTime(23, 59, 59)));

    // Compute fees
    $fee = calculate_student_fee($conn, $studentId, (int)$planId, $isNew, $isLate);

    $nextDueISO   = $due->format('Y-m-d');
    $nextDueLabel = $due->format('M j, Y');

    return [
        (float)$fee['total'],
        $nextDueLabel,
        $nextDueISO,
        $fee,
        [
            'isNew'            => $isNew,
            'isLate'           => $isLate,
            'cycle_settled'    => $cycleSettled,
            'anchor_due_iso'   => $anchorISO,           // now the anchor month
            'plan_id'          => (int)$planId,
            'duration_months'  => (int)$durationMonths,
        ]
    ];
}
