<?php
// File: dashboard/includes/fee_calculator.php
declare(strict_types=1);

/**
 * Calculate all fee components for a given student + plan.
 *
 * Referral discounts:
 *  - Referrer (existing): 10% applies on next billing (not immediately).
 *  - Referee (new student): 5% applies on their next billing (not initial).
 *
 * @return array{
 *   base_fee: float, enrollment_fee: float, advance_fee: float, late_fee: float,
 *   referral_percent: float, referral_amount: float,
 *   gst_percent: float, gst_amount: float, total: float
 * }
 */
function calculate_student_fee(
    mysqli $conn,
    int $studentId,
    int $planId,
    bool $isNewStudent = true,
    bool $isLate       = false
): array {
    // ── student flags ──────────────
    $stmt = $conn->prepare("
        SELECT COALESCE(pending_discount_percent,0),
               COALESCE(skip_enroll_fee,0),
               COALESCE(skip_advance_fee,0)
          FROM students
         WHERE id = ?
         LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($pendingDisc, $skipEnroll, $skipAdvance);
    $ok = $stmt->fetch();
    $stmt->close();

    if (!$ok) {
        return [
            'base_fee'=>0.0,'enrollment_fee'=>0.0,'advance_fee'=>0.0,'late_fee'=>0.0,
            'referral_percent'=>0.0,'referral_amount'=>0.0,
            'gst_percent'=>0.0,'gst_amount'=>0.0,'total'=>0.0
        ];
    }

    // ── plan details ───────────────
    $stmt = $conn->prepare("
        SELECT amount, enrollment_fee, advance_fee,
               prorate_allowed, late_fee, gst_percent, duration_months
          FROM payment_plans
         WHERE id = ?
         LIMIT 1
    ");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $stmt->bind_result($base, $enrollDB, $advanceDB, $prorateOk, $lateDB, $gstPct, $duration);
    $ok = $stmt->fetch();
    $stmt->close();

    if (!$ok) {
        return [
            'base_fee'=>0.0,'enrollment_fee'=>0.0,'advance_fee'=>0.0,'late_fee'=>0.0,
            'referral_percent'=>0.0,'referral_amount'=>0.0,
            'gst_percent'=>0.0,'gst_amount'=>0.0,'total'=>0.0
        ];
    }

    // ── one-time fees ──────────────
    $enrollFee  = ($isNewStudent && !$skipEnroll)  ? (float)$enrollDB  : 0.0;
    $advanceFee = ($isNewStudent && !$skipAdvance) ? (float)$advanceDB : 0.0;

    // ── late fee ───────────────────
    $lateFee = (!$isNewStudent && $isLate) ? (float)$lateDB : 0.0;

    // ── proration ──────────────────
    $todayDay = (int)date('j');
    if ((int)$duration === 1 && (int)$prorateOk === 1 && $todayDay > 15) {
        $base = round((float)$base * 0.5, 2);
    } else {
        $base = (float)$base;
    }

    // ── referral discount ──────────
    $refPerc = (!$isNewStudent) ? max(0.0, (float)$pendingDisc) : 0.0;
    $refAmt  = round($base * ($refPerc / 100.0), 2);
    $tuitionAfterDisc = max(0.0, $base - $refAmt);

    // ── subtotal & GST ─────────────
    $subtotal = $tuitionAfterDisc + $enrollFee + $advanceFee + $lateFee;
    $gstPct   = max(0.0, (float)$gstPct);
    $gstAmt   = round($subtotal * ($gstPct / 100.0), 2);
    $total    = round($subtotal + $gstAmt, 2);

    return [
        'base_fee'         => round($base, 2),
        'enrollment_fee'   => round($enrollFee, 2),
        'advance_fee'      => round($advanceFee, 2),
        'late_fee'         => round($lateFee, 2),
        'referral_percent' => round($refPerc, 2),
        'referral_amount'  => round($refAmt, 2),
        'gst_percent'      => $gstPct,
        'gst_amount'       => $gstAmt,
        'total'            => $total,
    ];
}
