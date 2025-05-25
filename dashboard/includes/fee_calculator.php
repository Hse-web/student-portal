<?php
// dashboard/includes/fee_calculator.php

/**
 * Returns detailed fee breakdown and total due.
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
    // 1) Fetch plan + student flags
    $stmt = $conn->prepare("
      SELECT 
        p.amount            AS base_fee,
        p.enrollment_fee,
        p.advance_fee,
        p.prorate_allowed,
        p.late_fee,
        p.gst_percent,
        p.duration_months   AS duration,
        s.pending_discount_percent,
        s.is_legacy
      FROM payment_plans p
      JOIN students      s
        ON s.group_name = p.group_name
       AND s.centre_id  = p.centre_id
     WHERE p.id = ? AND s.id = ?
    ");
    $stmt->bind_param('ii',$planId,$studentId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$res) {
      return ['total'=>0];
    }

    $day          = (int)date('j');
    $base         = (float)$res['base_fee'];
    $dur          = (int)$res['duration'];
    $enrollFee    = $isNewStudent && !$res['is_legacy']
                   ? (float)$res['enrollment_fee'] : 0.00;
    $advanceFee   = $isNewStudent && !$res['is_legacy']
                   ? (float)$res['advance_fee']    : 0.00;
    $lateFee      = $isLate ? (float)$res['late_fee'] : 0.00;
    $gstPct       = (float)$res['gst_percent'];
    $referralPct  = (float)$res['pending_discount_percent'];

    // 2) Mid-month proration (1-month & allowed)
    if ($dur===1 && $res['prorate_allowed'] && $day>15) {
      $base *= 0.5;
    }

    // 3) Subtotal before referral
    $subtotal     = $base + $enrollFee + $advanceFee + $lateFee;

    // 4) Apply referral discount on base
    $referralAmt  = round($base * ($referralPct/100),2);
    $subtotal    -= $referralAmt;

    // 5) GST on net subtotal
    $gstAmt       = round($subtotal * ($gstPct/100),2);

    // 6) Final total
    $total        = round($subtotal + $gstAmt,2);

    // 7) Clear one-time referral
    if ($referralPct>0) {
      $u = $conn->prepare("
        UPDATE students
           SET pending_discount_percent = 0
         WHERE id = ?
      ");
      $u->bind_param('i',$studentId);
      $u->execute();
      $u->close();
    }

    return [
      'base_fee'           => $base,
      'enrollment_fee'     => $enrollFee,
      'advance_fee'        => $advanceFee,
      'late_fee'           => $lateFee,
      'referral_percent'   => $referralPct,
      'referral_amount'    => $referralAmt,
      'gst_percent'        => $gstPct,
      'gst_amount'         => $gstAmt,
      'total'              => $total,
    ];
}
