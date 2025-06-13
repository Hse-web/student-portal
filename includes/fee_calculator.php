<?php
// File: dashboard/includes/fee_calculator.php

/**
 * Calculate fees for a student+plan.
 * 
 * - No automatic join-day discount.
 * - Late fee only if NOT new student.
 * - Referral discount only if pending_discount_percent > 0.
 *
 * @return array{total: float, ...}
 */
function calculate_student_fee(
    mysqli $conn,
    int $studentId,
    int $planId,
    bool $isNewStudent = true,
    bool $isLate       = false
): array {
    // 1) Student flags
    $stmt = $conn->prepare("
      SELECT pending_discount_percent AS referral_pct,
             skip_enroll_fee           AS skip_enroll,
             skip_advance_fee          AS skip_advance
        FROM students
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($referralPct, $skipEnroll, $skipAdvance);
    if (! $stmt->fetch()) {
        $stmt->close();
        return ['total'=>0.00];
    }
    $stmt->close();

    // 2) Plan data
    $stmt = $conn->prepare("
      SELECT amount           AS base_fee,
             enrollment_fee   AS enroll_db,
             advance_fee      AS advance_db,
             prorate_allowed,
             late_fee         AS late_db,
             gst_percent,
             duration_months
        FROM payment_plans
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $stmt->bind_result(
      $base,
      $enrollDB,
      $advanceDB,
      $prorateAllowed,
      $lateDB,
      $gstPct,
      $duration
    );
    if (! $stmt->fetch()) {
        $stmt->close();
        return ['total'=>0.00];
    }
    $stmt->close();

    // 3) One-time fees
    $enrollFee  = ($isNewStudent && ! $skipEnroll)  ? $enrollDB  : 0.00;
    $advanceFee = ($isNewStudent && ! $skipAdvance) ? $advanceDB : 0.00;

    // 4) Late fee only for renewals
    $lateFee = (!$isNewStudent && $isLate) ? $lateDB : 0.00;

    // 5) Prorate if needed
    if ($duration === 1 && $prorateAllowed && (int)date('j') > 15) {
        $base *= 0.5;
    }

    // 6) Subtotal
    $subtotal = $base + $enrollFee + $advanceFee + $lateFee;

    // 7) Referral discount
    $refAmt = 0.00;
    if ($referralPct > 0) {
        $refAmt   = round($base * ($referralPct/100), 2);
        $subtotal -= $refAmt;
    }

    // 8) GST
    $gstAmt = round($subtotal * ($gstPct/100), 2);

    // 9) Total
    $total = round($subtotal + $gstAmt, 2);

    // 10) Clear referral percent if applied
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
      'referral_percent' => (float)$referralPct,
      'referral_amount'  => $refAmt,
      'gst_percent'      => (float)$gstPct,
      'gst_amount'       => $gstAmt,
      'total'            => $total,
    ];
}
