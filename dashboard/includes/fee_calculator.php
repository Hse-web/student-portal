<?php
// File: dashboard/includes/fee_calculator.php

/**
 * Calculate all fee components for a given student + plan.
 *
 * @param mysqli $conn
 * @param int    $studentId
 * @param int    $planId
 * @param bool   $isNewStudent
 * @param bool   $isLate
 * @return array{
 *   base_fee: float,
 *   enrollment_fee: float,
 *   advance_fee: float,
 *   late_fee: float,
 *   referral_percent: float,
 *   referral_amount: float,
 *   gst_percent: float,
 *   gst_amount: float,
 *   total: float
 * }
 */
function calculate_student_fee(
    mysqli $conn,
    int $studentId,
    int $planId,
    bool $isNewStudent = true,
    bool $isLate       = false
): array {
    // 1) get student flags
    $stmt = $conn->prepare("
      SELECT pending_discount_percent, skip_enroll_fee, skip_advance_fee
        FROM students
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($refPerc, $skipEnroll, $skipAdvance);
    if (!$stmt->fetch()) {
        $stmt->close();
        return ['total'=>0.00];
    }
    $stmt->close();

    // 2) get plan data
    $stmt = $conn->prepare("
      SELECT amount, enrollment_fee, advance_fee,
             prorate_allowed, late_fee, gst_percent, duration_months
        FROM payment_plans
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $stmt->bind_result(
      $base, $enrollDB, $advanceDB,
      $prorateOk, $lateDB, $gstPct,
      $duration
    );
    if (!$stmt->fetch()) {
        $stmt->close();
        return ['total'=>0.00];
    }
    $stmt->close();

    // 3) one-off fees
    $enrollFee = ($isNewStudent && !$skipEnroll)  ? $enrollDB  : 0.00;
    $advanceFee= ($isNewStudent && !$skipAdvance) ? $advanceDB : 0.00;

    // 4) late fee only on renewals
    $lateFee = (!$isNewStudent && $isLate) ? $lateDB : 0.00;

    // 5) prorate monthly plans after day-15
    if ($duration === 1 && $prorateOk && (int)date('j') > 15) {
      $base *= 0.5;
    }

    // 6) referral discount
    $refAmt = round($base * ($refPerc/100), 2);
    if ($refAmt > 0) {
      $subtotal = $base - $refAmt + $enrollFee + $advanceFee + $lateFee;
      // clear it once applied
      $upd = $conn->prepare("UPDATE students SET pending_discount_percent=0 WHERE id=?");
      $upd->bind_param('i',$studentId);
      $upd->execute();
      $upd->close();
    } else {
      $subtotal = $base + $enrollFee + $advanceFee + $lateFee;
      $refAmt = 0.00;
    }

    // 7) GST
    $gstAmt = round($subtotal * ($gstPct/100), 2);

    // 8) total
    $total = round($subtotal + $gstAmt, 2);

    return [
      'base_fee'         => round($base, 2),
      'enrollment_fee'   => round($enrollFee,2),
      'advance_fee'      => round($advanceFee,2),
      'late_fee'         => round($lateFee,2),
      'referral_percent' => (float)$refPerc,
      'referral_amount'  => $refAmt,
      'gst_percent'      => (float)$gstPct,
      'gst_amount'       => $gstAmt,
      'total'            => $total,
    ];
}
