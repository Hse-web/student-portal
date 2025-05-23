<?php
// dashboard/includes/fee_calculator.php

/**
 * Returns detailed fee breakdown and total due.
 *
 * @param mysqli $conn
 * @param int $studentId
 * @param int $planId
 * @param bool $isNewStudent  true on first-ever enroll
 * @param bool $isLate        true if paying after day 5
 * @return array
 */
function calculate_student_fee(mysqli $conn, int $studentId, int $planId, bool $isNewStudent=true, bool $isLate=false): array {
    // 1) Load plan + student pending discount
    $stmt = $conn->prepare("
    SELECT p.amount             AS base_fee,
           p.enrollment_fee,
           p.advance_fee,
           p.prorate_allowed,
           p.late_fee,
           p.gst_percent,
           p.duration_months,
           s.pending_discount_percent,
           s.is_legacy
      FROM payment_plans p
      JOIN students      s
        ON s.group_name = p.group_name
       AND s.centre_id = p.centre_id
     WHERE p.id = ? AND s.id = ?
  ");
    $stmt->bind_param('ii',$planId,$studentId);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$res) return ['total'=>0];

    $day      = (int)date('j');
    $base     = (float)$res['base_fee'];
    $dur      = (int)$res['duration_months'];
    $enroll   = $isNewStudent && !$res['is_legacy']
                ? (float)$res['enrollment_fee'] : 0.00;
    $advance  = $isNewStudent && !$res['is_legacy']
                ? (float)$res['advance_fee']    : 0.00;
   // proration cutoff logic: if 1-month & prorate_allowed, and after day 15 => base=0
  if ($res['prorate_allowed'] && $res['base_fee']>0 && $res['base_fee']==$base) {
    // only for 1-month plans: check via base==month fee or via separate duration param
    if ($day>15) {
      $base = 0.00;
    }
  }
    $late     = ($isLate) ? (float)$res['late_fee'] : 0.00;
    $gstPct   = (float)$res['gst_percent'];
    $refPct   = (float)$res['pending_discount_percent'];

    // 2) Subtotal before discounts
    $subtotal = $base + $enroll + $advance + $late;

    // 3) Apply referral discount as percentage on base
    $refAmt   = round($base * ($refPct/100),2);
    $subtotal -= $refAmt;

    // 4) GST on net subtotal
    $gstAmt   = round($subtotal * ($gstPct/100), 2);

    // 5) Final total
    $total    = round($subtotal + $gstAmt, 2);

    // 6) Clear the one-time referral discount
    if ($refPct>0) {
      $u = $conn->prepare("
        UPDATE students
           SET pending_discount_percent = 0
         WHERE id = ?
      ");
      $u->bind_param('i',$studentId);
      $u->execute();
    }

    return [
      'base_fee'            => $base,
      'enrollment_fee'      => $enroll,
      'advance_fee'         => $advance,
      'late_fee'            => $late,
      'gst_percent'         => $gstPct,
      'gst_amount'          => $gstAmt,
      'referral_percent'    => $refPct,
      'referral_amount'     => $refAmt,
      'total'               => $total,
    ];
}
