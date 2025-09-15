<?php
// File: dashboard/includes/functions.php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// canonical calculator
require_once __DIR__ . '/fee_calculator.php';

/* ────────────────────────────────────────────────────────────
 * formatting + html helpers
 * ──────────────────────────────────────────────────────────── */
if (!function_exists('inr_str')) {
    function inr_str($n): string { return number_format((float)$n, 2, '.', ','); }
}
if (!function_exists('e')) {
    function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ────────────────────────────────────────────────────────────
 * flash helpers
 * ──────────────────────────────────────────────────────────── */
function set_flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}
function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) { $f = $_SESSION['flash']; unset($_SESSION['flash']); return $f; }
    return null;
}
function display_flash(): void {
    $f = get_flash(); if (!$f) return;
    $cls = ['success'=>'success','danger'=>'danger','warning'=>'warning'][$f['type']] ?? 'info';
    echo '<div class="alert alert-'.$cls.'">'.e($f['msg']).'</div>';
}

/* ────────────────────────────────────────────────────────────
 * group helpers
 * ──────────────────────────────────────────────────────────── */
function get_current_group_label(mysqli $conn, int $studentId): string {
    $stmt = $conn->prepare("
      SELECT ag.label
        FROM student_promotions sp
        JOIN art_groups ag ON ag.id = sp.art_group_id
       WHERE sp.student_id = ? AND sp.is_applied = 1
       ORDER BY sp.effective_date DESC LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute(); $stmt->bind_result($label);
    if ($stmt->fetch()) { $stmt->close(); return (string)$label; }
    $stmt->close();

    $stmt = $conn->prepare("SELECT group_name FROM students WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $studentId);
    $stmt->execute(); $stmt->bind_result($fallback);
    $stmt->fetch(); $stmt->close();
    return $fallback ?: '';
}

/* ────────────────────────────────────────────────────────────
 * payment helpers (core)
 * ──────────────────────────────────────────────────────────── */

/** any payment row anchored before $anchorIso ? */
function has_prior_payment_before(mysqli $conn, int $studentId, string $anchorIso): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
          FROM payments
         WHERE student_id = ?
           AND COALESCE(paid_at, due_date) < ?
    ");
    $stmt->bind_param('is', $studentId, $anchorIso);
    $stmt->execute(); $stmt->bind_result($cnt);
    $stmt->fetch(); $stmt->close();
    return ((int)$cnt) > 0;
}

/** count paid rows (kept for stats) */
function count_paid(mysqli $conn, int $studentId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE student_id=? AND status='Paid'");
    $stmt->bind_param('i', $studentId); $stmt->execute(); $stmt->bind_result($cnt);
    $stmt->fetch(); $stmt->close(); return (int)$cnt;
}

/** last paid_at for base timeline */
function get_last_paid_at(mysqli $conn, int $studentId): ?string {
    $stmt = $conn->prepare("
        SELECT paid_at FROM payments
         WHERE student_id=? AND status='Paid'
         ORDER BY paid_at DESC LIMIT 1
    ");
    $stmt->bind_param('i', $studentId); $stmt->execute(); $stmt->bind_result($paid);
    $ok = $stmt->fetch(); $stmt->close();
    return ($ok && $paid) ? $paid : null;
}

/** subscription + plan meta */
function get_latest_subscription(mysqli $conn, int $studentId): ?array {
    $stmt = $conn->prepare("
        SELECT s.plan_id, s.subscribed_at,
               COALESCE(p.duration_months,1),
               COALESCE(p.gst_percent,0),
               COALESCE(p.amount,0)
          FROM student_subscriptions s
          JOIN payment_plans p ON p.id = s.plan_id
         WHERE s.student_id = ?
         ORDER BY s.subscribed_at DESC LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($planId, $subAt, $duration, $gstPercent, $planAmt);
    $ok = $stmt->fetch(); $stmt->close();
    return $ok ? [
        'plan_id'       => (int)$planId,
        'subscribed_at' => $subAt,
        'duration'      => (int)$duration,
        'gst_percent'   => (float)$gstPercent,
        'plan_amount'   => (float)$planAmt,
    ] : null;
}

/** latest proof status (Approved/Pending/Rejected) for a payment */
function proof_status_for_payment(mysqli $conn, int $paymentId): ?string {
    $stmt = $conn->prepare("
        SELECT status FROM payment_proofs
         WHERE payment_id = ? ORDER BY id DESC LIMIT 1
    ");
    $stmt->bind_param('i', $paymentId); $stmt->execute(); $stmt->bind_result($status);
    $ok = $stmt->fetch(); $stmt->close();
    return $ok ? $status : null;
}

/** find the payment row that belongs to a given month (handles 29-prev → 5) */
function find_payment_for_month(mysqli $conn, int $studentId, int $year, int $month): ?array {
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));

    // inside the month by anchor
    $stmt = $conn->prepare("
        SELECT payment_id, status, amount_due, amount_paid, due_date, paid_at
          FROM payments
         WHERE student_id = ?
           AND COALESCE(paid_at, due_date) BETWEEN ? AND ?
         ORDER BY COALESCE(paid_at, due_date) DESC, payment_id DESC
         LIMIT 1
    ");
    $stmt->bind_param('iss', $studentId, $monthStart, $monthEnd);
    $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if ($row) return $row;

    // early window (prev 29 .. due 5)
    $tz    = new DateTimeZone('Asia/Kolkata');
    $due   = DateTime::createFromFormat('Y-m-d', $monthStart, $tz);
    $prev  = (clone $due)->modify('first day of previous month');
    $startDay   = min(29, (int)$prev->format('t'));
    $earlyStart = $prev->format('Y-m-') . sprintf('%02d', $startDay) . ' 00:00:00';
    $earlyEnd   = $due->format('Y-m-')  . '05 23:59:59';

    $stmt = $conn->prepare("
        SELECT payment_id, status, amount_due, amount_paid, due_date, paid_at
          FROM payments
         WHERE student_id = ?
           AND paid_at BETWEEN ? AND ?
         ORDER BY paid_at DESC, payment_id DESC
         LIMIT 1
    ");
    $stmt->bind_param('iss', $studentId, $earlyStart, $earlyEnd);
    $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

/* month-span utilities */
function months_between(string $startYmd, int $duration): array {
    $out = []; $d = new DateTime($startYmd); $d->modify('first day of this month');
    for ($i=0;$i<$duration;$i++) { $out[] = $d->format('Y-m'); $d->modify('+1 month'); }
    return $out;
}

/** expand approved payments into covered months */
function approved_cycles(mysqli $conn, int $studentId): array {
    $stmt = $conn->prepare("
        SELECT p.payment_id, COALESCE(p.paid_at, p.due_date) AS anchor_ts
          FROM payments p
          JOIN payment_proofs pr ON pr.payment_id = p.payment_id AND pr.status='Approved'
         WHERE p.student_id = ?
         ORDER BY anchor_ts DESC
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute(); $rs = $stmt->get_result();

    $cycles = [];
    while ($r = $rs->fetch_assoc()) {
        if (empty($r['anchor_ts'])) continue;

        $q = $conn->prepare("
            SELECT pl.duration_months
              FROM student_subscriptions ss
              JOIN payment_plans pl ON pl.id = ss.plan_id
             WHERE ss.student_id = ? AND ss.subscribed_at <= ?
             ORDER BY ss.subscribed_at DESC LIMIT 1
        ");
        $q->bind_param('is', $studentId, $r['anchor_ts']);
        $q->execute(); $sub = $q->get_result()->fetch_assoc(); $q->close();

        $duration = max(1, (int)($sub['duration_months'] ?? 1));
        $span = months_between((new DateTime($r['anchor_ts']))->format('Y-m-01'), $duration);
        foreach ($span as $mk) $cycles[$mk] = true;
    }
    $stmt->close();
    return $cycles; // 'YYYY-MM' => true
}

/** is a year-month already covered by an approved cycle? */
function is_month_covered_by_approved(mysqli $conn, int $studentId, int $year, int $month): bool {
    $key = sprintf('%04d-%02d', $year, $month);
    $covered = approved_cycles($conn, $studentId);
    return isset($covered[$key]);
}

/* ────────────────────────────────────────────────────────────
 * holds + window helpers
 * ──────────────────────────────────────────────────────────── */
function pick_base_date(mysqli $conn, int $studentId, ?string $fallbackSubscribedAt): ?string {
    $lastPaid = get_last_paid_at($conn, $studentId);
    return $lastPaid ?: ($fallbackSubscribedAt ?: null);
}

/** upload window: 29(prev) 00:00 → 5(due) 23:59:59 */
function is_payment_window_open(?string $dueDateYmd): bool {
    if (!$dueDateYmd || $dueDateYmd === '0000-00-00') return false;
    $tz  = new DateTimeZone('Asia/Kolkata');
    $due = DateTime::createFromFormat('Y-m-d', $dueDateYmd, $tz); if (!$due) return false;
    $prev  = (clone $due)->modify('first day of previous month');
    $startDay = min(29, (int)$prev->format('t'));
    $start = DateTime::createFromFormat('Y-m-d H:i:s', $prev->format('Y-m-').sprintf('%02d',$startDay).' 00:00:00', $tz);
    $end   = DateTime::createFromFormat('Y-m-d H:i:s', $due->format('Y-m-').'05 23:59:59', $tz);
    $now   = new DateTime('now', $tz);
    return ($start && $end && $now >= $start && $now <= $end);
}

/** also active after day 5 in the due month */
function is_active_cycle(?string $dueDateYmd): bool {
    if (!$dueDateYmd || $dueDateYmd === '0000-00-00') return false;
    if (is_payment_window_open($dueDateYmd)) return true;
    $tz  = new DateTimeZone('Asia/Kolkata');
    $due = DateTime::createFromFormat('Y-m-d', $dueDateYmd, $tz); if (!$due) return false;
    $now = new DateTime('now', $tz);
    return ($now->format('Y-m') === $due->format('Y-m') && (int)$now->format('j') > 5);
}

/** labels for UI */
function upload_window_bounds(?string $dueDateYmd): ?array {
    if (!$dueDateYmd || $dueDateYmd === '0000-00-00') return null;
    $tz  = new DateTimeZone('Asia/Kolkata');
    $due = DateTime::createFromFormat('Y-m-d', $dueDateYmd, $tz); if (!$due) return null;
    $prev  = (clone $due)->modify('first day of previous month');
    $startDay = min(29, (int)$prev->format('t'));
    $start = DateTime::createFromFormat('Y-m-d H:i:s', $prev->format('Y-m-').sprintf('%02d',$startDay).' 00:00:00', $tz);
    $end   = DateTime::createFromFormat('Y-m-d H:i:s', $due->format('Y-m-').'05 23:59:59', $tz);
    return [
        'start_iso'   => $start? $start->format('Y-m-d H:i:s') : null,
        'end_iso'     => $end  ? $end->format('Y-m-d H:i:s')   : null,
        'start_label' => $start? $start->format('M j, Y · H:i').' IST' : null,
        'end_label'   => $end  ? $end->format('M j, Y · H:i').' IST'   : null,
    ];
}

/** billing holds: robust matching for YYYY-MM variants */
function is_month_on_hold(mysqli $conn, int $studentId, int $year, int $month): bool {
    $monthStart = sprintf('%04d-%02d-01', $year, $month);
    $monthEnd   = date('Y-m-t', strtotime($monthStart));
    $ymA = sprintf('%04d-%02d', $year, $month);
    $ymB = sprintf('%04d-%d' , $year, $month);
    $ymA2 = str_replace('-', '/', $ymA);
    $ymB2 = str_replace('-', '/', $ymB);

    $sql = "
        SELECT 1
          FROM billing_holds
         WHERE student_id = ?
           AND status = 'Approved'
           AND (
                 NOT (end_month < ? OR start_month > ?)
              OR REPLACE(hold_month, '/', '-') IN (?, ?)
              OR hold_month IN (?, ?)
           )
         LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('issssss', $studentId, $monthStart, $monthEnd, $ymA, $ymB, $ymA2, $ymB2);
    $stmt->execute(); $hit = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
    return $hit;
}

/* invoices */
function invoice_url(int $paymentId): string {
    // adjust if your route differs
    return "/artovue/dashboard/student/invoice.php?payment_id=".(int)$paymentId;
}

/* ────────────────────────────────────────────────────────────
 * fee computation + ensuring a due row exists
 * ──────────────────────────────────────────────────────────── */
function next_due_from_base(string $baseDate, int $durationMonths): array {
    try {
        $dt = new DateTime($baseDate);
        $dt->modify("+{$durationMonths} months");
        $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), 5);
        return ['iso' => $dt->format('Y-m-d'), 'label' => $dt->format('M j, Y')];
    } catch (Throwable $e) { return ['iso'=>null,'label'=>null]; }
}

/**
 * main calculator for the *current* cycle (no double enrollment fee).
 * also returns proof status + can_upload_now and window labels.
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    $sub = get_latest_subscription($conn, $studentId);
    if (!$sub) {
        return [
            'error' => 'No active subscription found.',
            'due_date'=>null,'due_label'=>null,
            'plan_fee'=>0,'enrollment_fee'=>0,'advance_fee'=>0,'late_fee'=>0,
            'gst'=>0,'gst_percent'=>0,'total'=>0,'payment_id'=>null,'payment_status'=>null,
            'proof_status'=>null,'can_upload_now'=>false,'upload_window'=>null,
        ];
    }

    $planId = (int)$sub['plan_id']; $duration = max(1, (int)$sub['duration']);

    // 1) nominal next due
    $base = pick_base_date($conn, $studentId, $sub['subscribed_at'] ?? date('Y-m-d'));
    $due  = next_due_from_base($base, $duration);
    $dueIso = $due['iso']; $dueLabel = $due['label'];

    // 2) skip covered/hold months
    while ($dueIso) {
        $yy = (int)substr($dueIso,0,4); $mm = (int)substr($dueIso,5,2);
        if (!is_month_covered_by_approved($conn,$studentId,$yy,$mm) && !is_month_on_hold($conn,$studentId,$yy,$mm)) break;
        $due = next_due_from_base($dueIso, $duration);
        $dueIso = $due['iso']; $dueLabel = $due['label'];
    }

    // 3) ensure a payments row exists for that cycle
    $row = $dueIso ? find_payment_for_month($conn, $studentId, (int)substr($dueIso,0,4), (int)substr($dueIso,5,2)) : null;
    if (!$row && $dueIso) {
        $isNewFirstCycle = !has_prior_payment_before($conn, $studentId, $dueIso);
        $fee0 = calculate_student_fee($conn, $studentId, $planId, $isNewFirstCycle, false);
        $stmt = $conn->prepare("
            INSERT INTO payments (student_id, status, amount_paid, amount_due, due_date, paid_at)
            VALUES (?, 'Pending', 0.00, ?, ?, NULL)
        ");
        $stmt->bind_param('ids', $studentId, $fee0['total'], $dueIso);
        $stmt->execute(); $newPaymentId = $stmt->insert_id; $stmt->close();
        $row = ['payment_id'=>$newPaymentId,'status'=>'Pending','amount_due'=>$fee0['total'],'amount_paid'=>0.00,'due_date'=>$dueIso,'paid_at'=>null];
    }

    $paymentId = $row ? (int)$row['payment_id'] : null;
    $statusDb  = $row ? (string)$row['status'] : null;
    $proof     = $paymentId ? proof_status_for_payment($conn, $paymentId) : null;

    // 4) "new" means: no anchor before this cycle → only then charge enrollment
    $isNew = $dueIso ? !has_prior_payment_before($conn, $studentId, $dueIso) : false;

    // 5) late fee: only existing students, this due month after 5th, not approved yet
    $isLate = false;
    if (!$isNew && $dueIso && strcasecmp((string)$proof,'Approved') !== 0) {
        $tz = new DateTimeZone('Asia/Kolkata');
        $now  = new DateTime('now', $tz);
        $due5 = DateTime::createFromFormat('Y-m-d H:i:s', substr($dueIso,0,7).'-05 23:59:59', $tz);
        if ($now > $due5 && $now->format('Y-m') === substr($dueIso,0,7)) $isLate = true;
    }

    // 6) final fee using the canonical calculator
    $fee = calculate_student_fee($conn, $studentId, $planId, $isNew, $isLate);

    // 7) upload gating
$canUpload = false;
if ($statusDb === 'Pending' && !$proof) {
    $canUpload = true;
}
    return [
        'due_date'        => $dueIso,
        'due_label'       => $dueLabel,
        'plan_fee'        => $fee['base_fee'],
        'enrollment_fee'  => $fee['enrollment_fee'],
        'advance_fee'     => $fee['advance_fee'],
        'late_fee'        => $fee['late_fee'],
        'gst'             => $fee['gst_amount'],
        'gst_percent'     => $fee['gst_percent'],
        'total'           => $fee['total'],
        'payment_id'      => $paymentId,
        'payment_status'  => $statusDb ?: 'Due',
        'proof_status'    => $proof,                 // ← use this to show "Approval pending" only
        'can_upload_now'  => $canUpload,
        'upload_window'   => upload_window_bounds($dueIso),
    ];
}

/* per-row pricing (never double charges enrollment) */
function due_for_payment_row(mysqli $conn, int $studentId, int $paymentId): float {
    $stmt = $conn->prepare("
        SELECT p.due_date, p.status, ss.plan_id
          FROM payments p
          JOIN student_subscriptions ss ON ss.student_id = p.student_id
         WHERE p.payment_id = ? LIMIT 1
    ");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute(); $stmt->bind_result($due, $pStatus, $planId);
    $ok = $stmt->fetch(); $stmt->close();
    if (!$ok || !$planId || empty($due)) return 0.0;

    $tz = new DateTimeZone('Asia/Kolkata'); $now = new DateTime('now', $tz);
    $due5 = DateTime::createFromFormat('Y-m-d H:i:s', substr($due,0,7).'-05 23:59:59', $tz);
    $isLate = (strcasecmp((string)$pStatus,'Approved') !== 0 && $now > $due5 && $now->format('Y-m') === substr($due,0,7));
    $isNewForThisRow = !has_prior_payment_before($conn, $studentId, $due);

    $fee = calculate_student_fee($conn, $studentId, (int)$planId, $isNewForThisRow, $isLate);
    return (float)($fee['total'] ?? 0.0);
}

/* last N rows (used by the side “Payment History”) */
function fetch_last_payments(mysqli $conn, int $studentId, int $limit = 3): array {
    // Precompute coverage from all Approved proofs
    $coveredMap = approved_cycles($conn, $studentId); // keys: 'YYYY-MM' => true

    $stmt = $conn->prepare("
        SELECT payment_id,
               COALESCE(paid_at, due_date) AS anchor_ts,
               COALESCE(amount_paid, 0)    AS amount_paid,
               COALESCE(status, 'Due')     AS pstatus
          FROM payments
         WHERE student_id = ?
         ORDER BY anchor_ts DESC, payment_id DESC
         LIMIT ?
    ");
    $stmt->bind_param('ii', $studentId, $limit);
    $stmt->execute();
    $rs = $stmt->get_result();

    $out = [];
    while ($r = $rs->fetch_assoc()) {
        $anchor = (string)($r['anchor_ts'] ?? '');
        $label  = ($anchor && $anchor !== '0000-00-00' && strtotime($anchor))
                ? date('F Y', strtotime($anchor))
                : '—';

        $ym = ($anchor && strlen($anchor) >= 7) ? substr($anchor, 0, 7) : null;

        // Start from DB status
        $status = (string)($r['pstatus'] ?? 'Due');

        // Prefer latest proof status
        if (!empty($r['payment_id'])) {
            $ps = proof_status_for_payment($conn, (int)$r['payment_id']); // Approved/Pending/Rejected/null
            if ($ps) $status = $ps;
        }

        // Force-map months inside an Approved multi-month span to "Covered"
        $sl = strtolower($status);
        if (($sl === 'pending' || $sl === 'due') && $ym && isset($coveredMap[$ym])) {
            $status = 'Covered';
        }

        // Compute a safe due amount for display (no double enrollment)
        $dueAmt = !empty($r['payment_id'])
                ? due_for_payment_row($conn, $studentId, (int)$r['payment_id'])
                : 0.0;

        $out[] = [
            'payment_id'  => (int)$r['payment_id'],
            'month_label' => $label,
            'amount_paid' => (float)($r['amount_paid'] ?? 0),
            'status'      => $status,
            'due_amount'  => (float)$dueAmt,
        ];
    }
    $stmt->close();
    return $out;
}


/* a) coverage for “today” (used for the summary card view) */
function active_approved_cycle_for_today(mysqli $conn, int $studentId): ?array {
    $tz = new DateTimeZone('Asia/Kolkata'); $todayYm = (new DateTime('now', $tz))->format('Y-m');

    $sql = "
        SELECT p.payment_id, COALESCE(p.paid_at, p.due_date) AS anchor_ts
          FROM payments p
          JOIN payment_proofs pr ON pr.payment_id = p.payment_id AND pr.status='Approved'
         WHERE p.student_id = ?
         ORDER BY anchor_ts DESC, p.payment_id DESC
    ";
    $st = $conn->prepare($sql); $st->bind_param('i', $studentId);
    $st->execute(); $rs = $st->get_result();

    while ($r = $rs->fetch_assoc()) {
        if (empty($r['anchor_ts'])) continue;

        $q = $conn->prepare("
            SELECT pl.duration_months
              FROM student_subscriptions ss
              JOIN payment_plans pl ON pl.id = ss.plan_id
             WHERE ss.student_id = ? AND ss.subscribed_at <= ?
             ORDER BY ss.subscribed_at DESC LIMIT 1
        ");
        $q->bind_param('is', $studentId, $r['anchor_ts']);
        $q->execute(); $sub = $q->get_result()->fetch_assoc(); $q->close();

        $dur = max(1, (int)($sub['duration_months'] ?? 1));
        $startYmd = (new DateTime($r['anchor_ts']))->format('Y-m-01');
        $span = months_between($startYmd, $dur);

        if (in_array($todayYm, $span, true)) {
            $lastYm = $span[count($span)-1];
            $next   = next_due_from_base($lastYm.'-01', 1);

            $s = DateTime::createFromFormat('Y-m', $span[0]);
            $e = DateTime::createFromFormat('Y-m', $lastYm);
            $label = ($s && $e) ? $s->format('M').'–'.$e->format('M Y') : '';

            $st->close();
            return [
                'payment_id'          => (int)$r['payment_id'],
                'span'                => $span,
                'start_ym'            => $span[0],
                'end_ym'              => $lastYm,
                'next_due'            => $next['iso'],
                'coverage_span_label' => $label,
            ];
        }
    }
    $st->close();
    return null;
}

/* b) find the latest approved payment row (for invoice button) */
function latest_approved_payment_row(mysqli $conn, int $studentId): ?array {
    $stmt = $conn->prepare("
        SELECT p.payment_id, p.amount_paid, p.amount_due, COALESCE(p.paid_at, p.due_date) AS anchor_ts
          FROM payments p
          JOIN payment_proofs pr ON pr.payment_id = p.payment_id AND pr.status='Approved'
         WHERE p.student_id = ?
         ORDER BY anchor_ts DESC, p.payment_id DESC
         LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

/* c) upcoming preview (next N dues skipping covered/holds) */
function first_upcoming_due(mysqli $conn, int $studentId): ?array {
    $sub = get_latest_subscription($conn, $studentId); if (!$sub) return null;
    $duration = max(1, (int)$sub['duration']);

    $base = pick_base_date($conn, $studentId, $sub['subscribed_at'] ?? date('Y-m-d'));
    $due  = next_due_from_base($base, $duration); $dueIso = $due['iso'];

    while ($dueIso) {
        $yy = (int)substr($dueIso,0,4); $mm = (int)substr($dueIso,5,2);
        if (!is_month_covered_by_approved($conn,$studentId,$yy,$mm) && !is_month_on_hold($conn,$studentId,$yy,$mm)) break;
        $due = next_due_from_base($dueIso, $duration); $dueIso = $due['iso'];
    }
    return $dueIso ? ['due_iso' => $dueIso, 'duration' => $duration] : null;
}

function upcoming_cycles(mysqli $conn, int $studentId, int $count = 3): array {
    $first = first_upcoming_due($conn, $studentId); if (!$first) return [];
    $dueIso = $first['due_iso']; $duration = (int)$first['duration'];

    $out = [];
    for ($i=0; $i<$count && $dueIso; $i++) {
        $yy = (int)substr($dueIso,0,4); $mm = (int)substr($dueIso,5,2);
        $row = find_payment_for_month($conn, $studentId, $yy, $mm);

        $paymentId = $row ? (int)$row['payment_id'] : null;
        $status    = 'Pending';
        if ($row) {
            $proof = proof_status_for_payment($conn, (int)$row['payment_id']);
            $raw   = strtolower((string)($row['status'] ?? 'due'));
            if (strcasecmp((string)$proof,'Approved') === 0)      $status = 'Paid';
            elseif ($raw === 'rejected')                          $status = 'Rejected';
            else                                                   $status = 'Pending';
        }

        $out[] = [
            'ym'         => sprintf('%04d-%02d', $yy, $mm),
            'due_iso'    => $dueIso,
            'label'      => date('F Y', strtotime(sprintf('%04d-%02d-01', $yy, $mm))),
            'payment_id' => $paymentId,
            'status'     => $status,
        ];

        $n = next_due_from_base($dueIso, $duration); $dueIso = $n['iso'];
        while ($dueIso) {
            $yy = (int)substr($dueIso,0,4); $mm = (int)substr($dueIso,5,2);
            if (!is_month_covered_by_approved($conn,$studentId,$yy,$mm) && !is_month_on_hold($conn,$studentId,$yy,$mm)) break;
            $n = next_due_from_base($dueIso, $duration); $dueIso = $n['iso'];
        }
    }
    return $out;
}

/* ────────────────────────────────────────────────────────────
 * admin: holds utilities
 * ──────────────────────────────────────────────────────────── */
function ym_bounds(string $ym): array { $start = $ym.'-01'; $end = date('Y-m-t', strtotime($start)); return [$start,$end]; }
function ym_range(string $startYm, ?string $endYm = null): array {
    $out = []; $start = DateTime::createFromFormat('Y-m', $startYm); if (!$start) return $out;
    $end   = $endYm ? DateTime::createFromFormat('Y-m', $endYm) : clone $start; if (!$end) return $out;
    $cur = clone $start; while ($cur <= $end) { $out[] = $cur->format('Y-m'); $cur->modify('+1 month'); } return $out;
}
function create_hold(mysqli $conn, int $studentId, string $ym, string $reason='Break', string $status='Approved'): bool {
    [$start,$end] = ym_bounds($ym);
    $sql = "INSERT INTO billing_holds
            (student_id,start_month,end_month,status,reason,hold_month,created_at,approved_at)
            VALUES (?,?,?,?,?,?,NOW(),IF(?='Approved',NOW(),NULL))
            ON DUPLICATE KEY UPDATE
              status=VALUES(status), reason=VALUES(reason),
              start_month=VALUES(start_month), end_month=VALUES(end_month),
              approved_at=IF(VALUES(status)='Approved', NOW(), approved_at)";
    $st=$conn->prepare($sql); if(!$st) return false;
    $st->bind_param('issssss',$studentId,$start,$end,$status,$reason,$ym,$status);
    $ok=$st->execute(); $st->close(); return (bool)$ok;
}
function list_recent_holds(mysqli $conn, int $limit=25): array {
    $sql="SELECT bh.id,bh.student_id,s.name AS student_name,bh.hold_month,bh.start_month,bh.end_month,bh.status,bh.reason,bh.created_at,bh.approved_at
            FROM billing_holds bh LEFT JOIN students s ON s.id=bh.student_id
           ORDER BY bh.created_at DESC LIMIT ?";
    $st=$conn->prepare($sql); $st->bind_param('i',$limit); $st->execute(); $rs=$st->get_result();
    $out=[]; while($r=$rs->fetch_assoc()) $out[]=$r; $st->close(); return $out;
}

/* ────────────────────────────────────────────────────────────
 * csrf
 * ──────────────────────────────────────────────────────────── */
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_valid(?string $token): bool { return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$token); }

/* ────────────────────────────────────────────────────────────
 * upgrades (same-group only) — unchanged
 * ──────────────────────────────────────────────────────────── */
function get_student_subscription(mysqli $conn, int $studentId): ?array {
    $sql="SELECT ss.plan_id, pp.duration_months, pp.centre_id
            FROM student_subscriptions ss JOIN payment_plans pp ON pp.id=ss.plan_id
           WHERE ss.student_id=? ORDER BY ss.id DESC LIMIT 1";
    $stmt=$conn->prepare($sql); $stmt->bind_param('i',$studentId); $stmt->execute();
    $res=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $res ?: null;
}
function next_duration_plan(mysqli $conn, int $centreId, string $groupLabel, int $currentDuration): ?array {
    $stmt=$conn->prepare("
        SELECT pp.id, pp.amount, pp.duration_months
          FROM payment_plans pp JOIN art_groups ag ON ag.id=pp.art_group_id
         WHERE pp.centre_id=? AND ag.label=? AND pp.duration_months > ?
         ORDER BY pp.duration_months ASC LIMIT 1
    ");
    $stmt->bind_param('isi',$centreId,$groupLabel,$currentDuration);
    $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $row ?: null;
}
function build_upgrade_offer(mysqli $conn, int $studentId, array $currentCycle): ?array {
    if (empty($currentCycle['due_date']) || strtolower((string)$currentCycle['payment_status'])==='approved' || !is_active_cycle($currentCycle['due_date'])) return null;
    $sub=get_student_subscription($conn,$studentId); if(!$sub) return null;
    $currentPlanId=(int)$sub['plan_id']; $currentDuration=(int)$sub['duration_months']; $centreId=(int)$sub['centre_id'];

    $stmt=$conn->prepare("SELECT amount FROM payment_plans WHERE id=? LIMIT 1");
    $stmt->bind_param('i',$currentPlanId); $stmt->execute(); $stmt->bind_result($curAmount); $stmt->fetch(); $stmt->close();
    if ($curAmount===null) return null;

    $curGroup=get_current_group_label($conn,$studentId); if($curGroup==='') return null;
    $durPlan=next_duration_plan($conn,$centreId,$curGroup,$currentDuration); if(!$durPlan) return null;

    $delta=round(((float)$durPlan['amount'] - (float)$curAmount),2);
    return ['show'=>true,'target_plan_id'=>(int)$durPlan['id'],'target_group'=>$curGroup,
            'current_amount'=>round((float)$curAmount,2),'next_amount'=>round((float)$durPlan['amount'],2),
            'delta'=>$delta,'duration_months'=>(int)$durPlan['duration_months']];
}

/* ────────────────────────────────────────────────────────────
 * notifications (soft — won’t fatal if table missing)
 * ──────────────────────────────────────────────────────────── */
function create_notification(mysqli $conn, array $studentIds, string $title, string $message): void {
    $stmt = $conn->prepare("INSERT INTO notifications (student_id, title, message, created_at, is_read) VALUES (?, ?, ?, NOW(), 0)");
    if (!$stmt) return; // silently ignore if table not present
    foreach ($studentIds as $sid) {
        $sid = (int)$sid; $t = $title; $m = $message;
        $stmt->bind_param('iss', $sid, $t, $m);
        $stmt->execute();
    }
    $stmt->close();
}

/* ────────────────────────────────────────────────────────────
 * payment summary view-model for the UI
 * ──────────────────────────────────────────────────────────── */
/**
 * Returns one of two modes your student_payment.php can render:
 *  - mode = 'covered' → show coverage_span_label, download invoice, and next_due
 *  - mode = 'due'     → show amounts, window text, and ONLY an "Approval pending" badge if proof_status='Pending'
 *    (No “Pending/Expected” wording in the summary.)
 */
function build_payment_summary_view(mysqli $conn, int $studentId): array {
    // if an approved cycle is currently covering today → show coverage + invoice
    $active = active_approved_cycle_for_today($conn, $studentId);
    if ($active) {
        $row = latest_approved_payment_row($conn, $studentId);
        $amount = $row ? ((float)$row['amount_paid'] ?: (float)$row['amount_due']) : 0.0;
        $nextLabel = $active['next_due'] ? date('M j, Y', strtotime($active['next_due'])) : null;
        return [
            'mode'                 => 'covered',
            'coverage_span_label'  => $active['coverage_span_label'],  // e.g., "Sep–Nov 2025"
            'invoice_payment_id'   => (int)$active['payment_id'],
            'invoice_amount'       => $amount,
            'invoice_url'          => invoice_url((int)$active['payment_id']),
            'next_due_iso'         => $active['next_due'],
            'next_due_label'       => $nextLabel,
        ];
    }

    // otherwise compute the current due (no status words in the summary)
    $due = compute_student_due($conn, $studentId);
    $win = $due['upload_window'] ?? null;

    return [
        'mode'                 => 'due',
        'due'                  => [
            'due_date'       => $due['due_date'],
            'due_label'      => $due['due_label'],
            'plan_fee'       => $due['plan_fee'],
            'enrollment_fee' => $due['enrollment_fee'],
            'advance_fee'    => $due['advance_fee'],
            'late_fee'       => $due['late_fee'],
            'gst'            => $due['gst'],
            'gst_percent'    => $due['gst_percent'],
            'total'          => $due['total'],
            'payment_id'     => $due['payment_id'],
        ],
        'approval_pending'     => (strcasecmp((string)($due['proof_status'] ?? ''), 'Pending') === 0),
        'can_upload_now'       => (bool)($due['can_upload_now'] ?? false),
        'window_text'          => ($win && $win['start_label'] && $win['end_label'])
                                  ? "Uploads will open ({$win['start_label']} → {$win['end_label']})"
                                  : null,
    ];
}

/* ────────────────────────────────────────────────────────────
 * minimal student info
 * ──────────────────────────────────────────────────────────── */
function fetch_student_info(mysqli $conn, int $studentId): ?array {
    $stmt = $conn->prepare("SELECT id, name, email, group_name FROM students WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $studentId); $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $res ?: null;
}
