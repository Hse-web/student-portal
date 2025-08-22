<?php
// File: dashboard/includes/holds.php

/**
 * Utilities for "billing holds" (student breaks / skipped months).
 *
 * Table: billing_holds
 *   id, student_id, start_month (YYYY-MM-01), end_month (YYYY-MM-01),
 *   status ENUM('Pending','Approved','Rejected','Cancelled'), reason, ...
 */

function bh_normalize_month(?string $iso): ?string {
    if (!$iso) return null;
    // Accept YYYY-MM or any date; normalize to first day of month
    $t = strtotime($iso);
    if (!$t) return null;
    return date('Y-m-01', $t);
}

function bh_get_approved_ranges(mysqli $conn, int $studentId): array {
    $rows = [];
    $stmt = $conn->prepare("
        SELECT start_month, end_month, reason
          FROM billing_holds
         WHERE student_id = ? AND status = 'Approved'
         ORDER BY start_month ASC
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $r['start_month'] = bh_normalize_month($r['start_month']);
        $r['end_month']   = bh_normalize_month($r['end_month']);
        if ($r['start_month'] && $r['end_month']) $rows[] = $r;
    }
    $stmt->close();
    return $rows;
}

/** True if $ym (YYYY-MM) falls inside an approved hold. */
function bh_month_in_hold(array $ranges, string $ym): bool {
    $check = $ym . '-01';
    foreach ($ranges as $r) {
        if ($check >= $r['start_month'] && $check <= $r['end_month']) return true;
    }
    return false;
}

/**
 * Push a due date forward until it lands in a month that is NOT on hold.
 * Returns [$dueDt, $pushedMonths, $lastHoldEnd]
 */
function bh_push_due_past_holds(mysqli $conn, int $studentId, DateTime $due): array {
    $tz     = $due->getTimezone();
    $ranges = bh_get_approved_ranges($conn, $studentId);

    $pushed  = 0;
    $lastEnd = null;

    // compare by month, not by exact day
    while (bh_month_in_hold($ranges, $due->format('Y-m'))) {
        // find the hold we are inside and jump to the month after its end
        foreach ($ranges as $r) {
            if ($due->format('Y-m-01') >= $r['start_month'] && $due->format('Y-m-01') <= $r['end_month']) {
                $lastEnd = $r['end_month'];
                $jump = new DateTime($r['end_month'], $tz);
                $jump->modify('first day of next month'); // first of next month
                $due = $jump;                              // now day=01
                $due->setDate((int)$due->format('Y'), (int)$due->format('m'), 5); // normalize to 5th
                $pushed++;
                break;
            }
        }
    }
    return [$due, $pushed, $lastEnd];
}
