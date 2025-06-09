<?php
// File: dashboard/includes/fee_calculator.php

/**
 * Returns detailed fee breakdown and total due.
 *
 * @param mysqli $conn
 * @param int    $studentId       The student’s ID
 * @param int    $planId          The plan’s ID
 * @param bool   $isNewStudent    True if this is the student’s first “paid” invoice
 * @param bool   $isLate          True if past the 5th of month (late fee applies)
 *
 * @return array {
 *   base_fee, enrollment_fee, advance_fee,
 *   late_fee, referral_percent, referral_amount,
 *   gst_percent, gst_amount, total
 * }
 */
function calculate_student_fee(
    mysqli $conn,
    int $studentId,
    int $planId,
    bool $isNewStudent = true,
    bool $isLate       = false
): array {
    // ─── 1) Pull plan fees + student skip‐flags + referral_pct from the DB ───
    $stmt = $conn->prepare("
      SELECT 
        p.amount            AS base_fee,
        p.enrollment_fee    AS db_enroll,
        p.advance_fee       AS db_advance,
        p.prorate_allowed   AS prorate_allowed,
        p.late_fee          AS db_late_fee,
        p.gst_percent       AS gst_percent,
        p.duration_months   AS duration,
        s.pending_discount_percent AS referral_pct,
        s.skip_enroll_fee   AS skip_enroll,
        s.skip_advance_fee  AS skip_advance
      FROM payment_plans p
      JOIN students      s
        ON s.group_name = p.group_name
       AND s.centre_id  = p.centre_id
     WHERE p.id = ?
       AND s.id = ?
      LIMIT 1
    ");
    $stmt->bind_param('ii', $planId, $studentId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (! $res) {
        // No matching plan/student → nothing to bill
        return ['total' => 0];
    }

    // ─── 2) Extract columns into local variables ──────────────────────
    $base           = (float)$res['base_fee'];
    $enrollDB       = (float)$res['db_enroll'];
    $advanceDB      = (float)$res['db_advance'];
    $lateDB         = (float)$res['db_late_fee'];
    $gstPct         = (float)$res['gst_percent'];
    $duration       = (int)  $res['duration'];
    $referralPct    = (float)$res['referral_pct'];
    $skipEnroll     = (int)  $res['skip_enroll'];
    $skipAdvance    = (int)  $res['skip_advance'];
    $prorateAllowed = (int)  $res['prorate_allowed'];

    // ─── 3) Determine the one‐time fees ─────────────────────────────────
    if (! $isNewStudent) {
        // If this is a renewal (not first paid invoice), zero out both one‐time fees
        $enrollFee  = 0.00;
        $advanceFee = 0.00;
    } else {
        // If this is the first paid invoice, charge one‐time fees only if skip_flag = 0
        $enrollFee  = $skipEnroll  ? 0.00 : $enrollDB;
        $advanceFee = $skipAdvance ? 0.00 : $advanceDB;
    }

    // ─── 4) Late fee if overdue ────────────────────────────────────────
    $lateFee = $isLate ? $lateDB : 0.00;

    // ─── 5) Mid‐month proration if 1‐month plan and prorate_allowed=1 ─
    $day = (int) date('j');
    if ($duration === 1 && $prorateAllowed && $day > 15) {
        $base *= 0.5;
    }

    // ─── 6) Subtotal before referral discount ─────────────────────────
    $subtotal = $base + $enrollFee + $advanceFee + $lateFee;

    // ─── 7) Apply referral discount (only on base) ────────────────────
    $referralAmt = round($base * ($referralPct / 100), 2);
    $subtotal   -= $referralAmt;

    // ─── 8) GST on net subtotal ───────────────────────────────────────
    $gstAmt = round($subtotal * ($gstPct / 100), 2);

    // ─── 9) Final total ───────────────────────────────────────────────
    $total = round($subtotal + $gstAmt, 2);

    // ─── 10) Clear one‐time referral discount (if any) ─────────────────
    if ($referralPct > 0) {
        $upd = $conn->prepare("
          UPDATE students
             SET pending_discount_percent = 0
           WHERE id = ?
        ");
        $upd->bind_param('i', $studentId);
        $upd->execute();
        $upd->close();
    }

    return [
        'base_fee'         => round($base, 2),
        'enrollment_fee'   => round($enrollFee, 2),
        'advance_fee'      => round($advanceFee, 2),
        'late_fee'         => round($lateFee, 2),
        'referral_percent' => $referralPct,
        'referral_amount'  => $referralAmt,
        'gst_percent'      => $gstPct,
        'gst_amount'       => $gstAmt,
        'total'            => $total,
    ];
}
