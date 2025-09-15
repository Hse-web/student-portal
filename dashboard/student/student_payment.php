<?php
// File: dashboard/student/student_payment.php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

require_once __DIR__ . '/../includes/functions.php';       // helpers + compute_student_due, etc.
require_once __DIR__ . '/../includes/fee_calculator.php';  // calculate_student_fee()

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: /artovue/login.php');
    exit;
}

/* ────────────────────────────────────────────────────────────
   Small local helpers (UI only)
   ──────────────────────────────────────────────────────────── */
function badge(string $text, string $class): string {
    return '<span class="badge '.$class.'">'.$text.'</span>';
}

/**
 * Compute an exact breakdown (base/enroll/advance/late/gst/total) for a specific
 * PAID/APPROVED payment row. This uses the subscription that was active on that row’s
 * anchor date and whether that row was the student’s first cycle and/or late.
 */
function breakdown_for_payment(mysqli $conn, int $studentId, int $paymentId): ?array {
    // 1) pull the payment row (anchor + amounts)
    $p = $conn->prepare("
        SELECT COALESCE(p.paid_at, p.due_date) AS anchor_ts,
               p.due_date, p.paid_at,
               COALESCE(p.amount_paid, 0) AS amount_paid,
               COALESCE(p.amount_due,  0) AS amount_due
          FROM payments p
         WHERE p.payment_id = ?
         LIMIT 1
    ");
    $p->bind_param('i', $paymentId);
    $p->execute();
    $row = $p->get_result()->fetch_assoc();
    $p->close();
    if (!$row || empty($row['anchor_ts'])) return null;

    $anchor = (string)$row['anchor_ts'];

    // 2) subscription/plan AT THAT TIME
    $s = $conn->prepare("
        SELECT ss.plan_id, ss.subscribed_at
          FROM student_subscriptions ss
         WHERE ss.student_id = ?
           AND ss.subscribed_at <= ?
         ORDER BY ss.subscribed_at DESC
         LIMIT 1
    ");
    $s->bind_param('is', $studentId, $anchor);
    $s->execute();
    $sub = $s->get_result()->fetch_assoc();
    $s->close();
    if (!$sub || empty($sub['plan_id'])) return null;

    $planId = (int)$sub['plan_id'];

    // 3) is this the first cycle BEFORE this anchor?
    $isFirst = !has_prior_payment_before($conn, $studentId, $anchor);

    // 4) late? (row-specific, using its paid_at vs due 5th)
    $isLate = false;
    if (!empty($row['due_date'])) {
        $tz   = new DateTimeZone('Asia/Kolkata');
        $due5 = DateTime::createFromFormat('Y-m-d H:i:s', substr($row['due_date'],0,7).'-05 23:59:59', $tz);
        $paid = !empty($row['paid_at']) ? new DateTime($row['paid_at'], $tz) : null;
        if ($paid && $due5 && $paid > $due5 && $paid->format('Y-m') === substr($row['due_date'],0,7)) {
            $isLate = true;
        }
    }

    // 5) canonical calculator
    $fee = calculate_student_fee($conn, $studentId, $planId, $isFirst, $isLate);

    // 6) read the actually-recorded paid/total for this invoice (amount_paid may be null historically)
    $totalPaid = (float)($row['amount_paid'] > 0 ? $row['amount_paid'] : $row['amount_due']);

    return [
        'base_fee'       => (float)$fee['base_fee'],
        'enrollment_fee' => (float)$fee['enrollment_fee'],
        'advance_fee'    => (float)$fee['advance_fee'],
        'late_fee'       => (float)$fee['late_fee'],
        'gst_percent'    => (float)$fee['gst_percent'],
        'gst_amount'     => (float)$fee['gst_amount'],
        'total'          => (float)$fee['total'],
        'total_paid'     => (float)$totalPaid,
    ];
}

/** Deduplicate history by month (prefer the most meaningful status for a month). */
function dedupe_history(array $rows): array {
    // higher wins
    $rank = [
        'paid'     => 5,
        'approved' => 5,
        'covered'  => 4,
        'pending'  => 3,
        'rejected' => 2,
        'due'      => 1,
        ''         => 0,
    ];
    $byMonth = [];
    foreach ($rows as $r) {
        $m  = (string)($r['month_label'] ?? '—');
        $st = strtolower((string)($r['status'] ?? ''));
        $key = $m;

        if (!isset($byMonth[$key])) {
            $byMonth[$key] = $r;
            continue;
        }
        $curr = strtolower((string)$byMonth[$key]['status']);
        if (($rank[$st] ?? 0) > ($rank[$curr] ?? 0)) {
            $byMonth[$key] = $r;
        } else if (($rank[$st] ?? 0) === ($rank[$curr] ?? 0)) {
            // tie-break: keep the one with payment_id (downloadable) or larger amount_paid
            $havePid = !empty($byMonth[$key]['payment_id']);
            $newPid  = !empty($r['payment_id']);
            if (!$havePid && $newPid) {
                $byMonth[$key] = $r;
            } elseif ($havePid === $newPid) {
                if ((float)($r['amount_paid'] ?? 0) > (float)($byMonth[$key]['amount_paid'] ?? 0)) {
                    $byMonth[$key] = $r;
                }
            }
        }
    }
    // keep original displayed order (the first time each month appeared)
    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $m = (string)($r['month_label'] ?? '—');
        if (!isset($seen[$m]) && isset($byMonth[$m])) {
            $out[] = $byMonth[$m];
            $seen[$m] = true;
        }
    }
    return $out;
}

/* ────────────────────────────────────────────────────────────
   Load primary data
   ──────────────────────────────────────────────────────────── */
$feeData     = compute_student_due($conn, $studentId);         // earliest non-approved cycle
$historyRows = fetch_last_payments($conn, $studentId, 6);      // pull a bit more, we’ll dedupe to 3
$upcoming    = upcoming_cycles($conn, $studentId, 3);
$coverage    = active_approved_cycle_for_today($conn, $studentId); // show green card if present

/* Guard defaults to avoid notices */
$fee = array_merge([
    'payment_id'       => null,
    'due_date'         => null,
    'due_label'        => null,
    'plan_fee'         => 0.0,
    'gst'              => 0.0,
    'late_fee'         => 0.0,
    'enrollment_fee'   => 0.0,
    'advance_fee'      => 0.0,
    'total'            => 0.0,
    'payment_status'   => null,
    'proof_status'     => null,
    'can_upload_now'   => false,
    'upload_window'    => ['start_label' => null, 'end_label' => null],
], is_array($feeData) ? $feeData : []);

$dueLabel          = $fee['due_label'] ?: '—';
$paymentStatus     = strtolower((string)($fee['payment_status'] ?? ''));
$proofStatusLower  = strtolower((string)($fee['proof_status']   ?? ''));
$canUpload         = (bool)($fee['can_upload_now'] ?? false);
$window            = is_array($fee['upload_window']) ? $fee['upload_window'] : ['start_label'=>null,'end_label'=>null];

/* GST % purely for display (for non-coverage card) */
$displaySubtotal = (float)$fee['plan_fee'] + (float)$fee['late_fee'] + (float)$fee['enrollment_fee'] + (float)$fee['advance_fee'];
$gstPercent      = $displaySubtotal > 0 ? round(100 * ((float)$fee['gst'] / $displaySubtotal)) : 0;

/* Normalize + dedupe history and keep last 3 */
$history = [];
foreach ((array)$historyRows as $r) {
    $history[] = [
        'payment_id'  => (int)($r['payment_id']  ?? 0),
        'month_label' => (string)($r['month_label'] ?? (isset($r['due_date']) ? date('F Y', strtotime($r['due_date'])) : '—')),
        'amount_paid' => (float)($r['amount_paid'] ?? 0),
        'due_amount'  => (float)($r['due_amount']  ?? 0),
        'status'      => (string)($r['status'] ?? 'Due'),
    ];
}
$history = array_slice(dedupe_history($history), 0, 3);

/* Coverage card breakdown + totals when coverage is active */
$coverageBreakdown = null;
$coveragePaid = 0.0;
if ($coverage && !empty($coverage['payment_id'])) {
    $coverageBreakdown = breakdown_for_payment($conn, $studentId, (int)$coverage['payment_id']);
    if ($coverageBreakdown) {
        $coveragePaid = (float)$coverageBreakdown['total_paid'];
    }
}

?>
<div class="container py-5">
  <h2 class="mb-4 fw-bold">Payment Summary</h2>

  <?php if (empty($feeData) && !$coverage): ?>
    <div class="alert alert-danger">We couldn't load your payment information. Please contact support.</div>
  <?php else: ?>
    <div class="row g-4">
      <!-- Left column: Summary (Coverage card if active; else the regular due card) -->
      <div class="col-lg-7">
        <div class="card shadow-sm border-0">
          <div class="card-header bg-white border-0 d-flex flex-column">
            <h5 class="mb-0">This Month's Due</h5>
            <small class="text-muted">
              <?php if ($coverage && !empty($coverage['next_due'])): ?>
                Next due on: <?= htmlspecialchars(date('M j, Y', strtotime($coverage['next_due']))) ?>
              <?php else: ?>
                Next due on: <?= htmlspecialchars($dueLabel) ?>
              <?php endif; ?>
            </small>
          </div>

          <div class="card-body">
            <?php if ($coverage): ?>
              <!-- COVERAGE highlight -->
              <div class="p-4 mb-3 rounded-3" style="background:#e8fff2;border:1px solid #bdf0cf;">
                <div class="d-flex align-items-start gap-3">
                  <div class="mt-1" style="width:28px;height:28px;border-radius:14px;background:#22c55e;display:flex;align-items:center;justify-content:center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M9 16.2l-3.5-3.5 1.41-1.41L9 13.38l7.09-7.09 1.41 1.41z"/></svg>
                  </div>
                  <div class="flex-grow-1">
                    <div class="text-uppercase fw-bold small text-success mb-1">Coverage</div>
                    <div class="h5 mb-1"><?= htmlspecialchars($coverage['coverage_span_label'] ?? '') ?></div>
                    <div class="text-muted">Your child's classes are covered in this period.</div>
                  </div>
                </div>
              </div>

              <!-- Breakdown for the covered (approved) invoice -->
              <?php if ($coverageBreakdown): ?>
                <div class="row gy-2">
                  <div class="col-6"><span class="text-muted">Plan Fee</span><div>₹<?= inr_str($coverageBreakdown['base_fee']) ?></div></div>
                  <div class="col-6"><span class="text-muted">GST (<?= (int)$coverageBreakdown['gst_percent'] ?>%)</span><div>₹<?= inr_str($coverageBreakdown['gst_amount']) ?></div></div>

                  <?php if ($coverageBreakdown['enrollment_fee'] > 0): ?>
                    <div class="col-4"><span class="text-muted">Enrollment</span><div>₹<?= inr_str($coverageBreakdown['enrollment_fee']) ?></div></div>
                  <?php endif; ?>
                  <?php if ($coverageBreakdown['advance_fee'] > 0): ?>
                    <div class="col-4"><span class="text-muted">Advance</span><div>₹<?= inr_str($coverageBreakdown['advance_fee']) ?></div></div>
                  <?php endif; ?>
                  <?php if ($coverageBreakdown['late_fee'] > 0): ?>
                    <div class="col-4"><span class="text-muted">Late Fee</span><div>₹<?= inr_str($coverageBreakdown['late_fee']) ?></div></div>
                  <?php endif; ?>
                </div>

                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center">
                  <strong>Total Paid</strong>
                  <strong class="text-success">₹<?= inr_str($coveragePaid) ?></strong>
                </div>
              <?php endif; ?>

              <div class="mt-3">
                <a class="btn btn-dark"
                   href="/artovue/dashboard/student/student_invoice.php?payment_id=<?= (int)$coverage['payment_id'] ?>">
                  Download Invoice
                </a>
              </div>

            <?php else: ?>
              <!-- REGULAR DUE CARD (no coverage active) -->
              <div class="row">
                <div class="col-6">
                  <span class="text-muted">Plan Fee</span>
                  <div>₹<?= inr_str($fee['plan_fee']) ?></div>
                </div>
                <div class="col-6">
                  <span class="text-muted">GST (<?= (int)$gstPercent ?>%)</span>
                  <div>₹<?= inr_str($fee['gst']) ?></div>
                </div>
              </div>

              <?php if ($fee['enrollment_fee'] > 0 || $fee['advance_fee'] > 0 || $fee['late_fee'] > 0): ?>
                <div class="row mt-3">
                  <?php if ($fee['enrollment_fee'] > 0): ?>
                    <div class="col-4"><span class="text-muted">Enrollment</span><div>₹<?= inr_str($fee['enrollment_fee']) ?></div></div>
                  <?php endif; ?>
                  <?php if ($fee['advance_fee'] > 0): ?>
                    <div class="col-4"><span class="text-muted">Advance</span><div>₹<?= inr_str($fee['advance_fee']) ?></div></div>
                  <?php endif; ?>
                  <?php if ($fee['late_fee'] > 0): ?>
                    <div class="col-4"><span class="text-muted">Late Fee</span><div>₹<?= inr_str($fee['late_fee']) ?></div></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <hr>
              <div class="d-flex justify-content-between align-items-center">
                <strong>Total Due</strong>
                <strong class="text-success">₹<?= inr_str($fee['total']) ?></strong>
              </div>
              <hr>

              <!-- Status + actions -->
              <div class="mt-2">
                <?php
                  $s = $proofStatusLower ?: $paymentStatus;
                  if ($s === 'approved' || $s === 'paid') {
                      echo badge('Paid', 'bg-success');
                  } elseif ($s === 'pending') {
                      echo badge('Pending', 'bg-warning text-dark');
                  } elseif ($s === 'rejected') {
                      echo badge('Rejected', 'bg-danger');
                  } else {
                      echo badge('Due', 'bg-secondary');
                  }
                ?>
              </div>

              <div class="mt-4">
                <?php if ($proofStatusLower === 'pending'): ?>
                  <span class="badge bg-warning text-dark">Admin Approval Pending</span>

                <?php elseif ($proofStatusLower === 'approved' || $paymentStatus === 'approved' || $paymentStatus === 'paid'): ?>
                  <?php if (!empty($fee['payment_id'])): ?>
                    <a class="btn btn-dark" href="/artovue/dashboard/student/student_invoice.php?payment_id=<?= (int)$fee['payment_id'] ?>">
                      Download Invoice
                    </a>
                  <?php endif; ?>

                <?php elseif ($proofStatusLower === 'rejected'): ?>
                  <?php if ($canUpload): ?>
                    <a href="/artovue/dashboard/student/upload_proof.php" class="btn btn-primary">
                      Re-Upload Payment Proof
                    </a>
                  <?php else: ?>
                    <small class="text-muted d-block">
                      Uploads will open
                      <?php if (!empty($window['start_label']) && !empty($window['end_label'])): ?>
                        (<?= htmlspecialchars($window['start_label']) ?> → <?= htmlspecialchars($window['end_label']) ?>)
                      <?php endif; ?>
                    </small>
                  <?php endif; ?>

                <?php else: /* no proof yet */ ?>
                  <?php if ($canUpload): ?>
                    <a href="/artovue/dashboard/student/upload_payment_proof.php" class="btn btn-primary">Upload Payment Proof</a>
                  <?php else: ?>
                    <small class="text-muted d-block">
                      Uploads will open
                      <?php if (!empty($window['start_label']) && !empty($window['end_label'])): ?>
                        (<?= htmlspecialchars($window['start_label']) ?> → <?= htmlspecialchars($window['end_label']) ?>)
                      <?php endif; ?>
                    </small>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Right column: History -->
      <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100 mb-4">
          <div class="card-header bg-white border-0">
            <h5 class="mb-0">Payment History</h5>
            <small class="text-muted">Last 3 months</small>
          </div>
          <div class="card-body">
            <?php if (empty($history)): ?>
              <p class="mb-0">No previous payment records found.</p>
            <?php else: ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($history as $row): ?>
                  <?php $hs = strtolower($row['status']); ?>
                  <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                    <div>
                      <div><strong>Month:</strong> <?= htmlspecialchars($row['month_label']) ?></div>

                      <?php if ($hs === 'approved' || $hs === 'paid'): ?>
                        <div class="text-muted">Paid: ₹<?= inr_str($row['amount_paid'] ?: $row['due_amount']) ?></div>
                      <?php elseif ($hs === 'covered'): ?>
                        <div class="text-muted">Covered</div>
                      <?php else: ?>
                        <div class="text-muted">Due: ₹<?= inr_str($row['due_amount'] ?? 0) ?></div>
                      <?php endif; ?>

                      <div class="text-muted">
                        Status:
                        <?php
                          if ($hs === 'approved' || $hs === 'paid') {
                              echo badge('Paid', 'bg-success');
                          } elseif ($hs === 'pending') {
                              echo badge('Pending', 'bg-warning text-dark');
                          } elseif ($hs === 'rejected') {
                              echo badge('Rejected', 'bg-danger');
                          } elseif ($hs === 'covered') {
                              echo badge('Covered', 'bg-info text-dark');
                          } else {
                              echo badge(ucfirst($row['status']), 'bg-secondary');
                          }
                        ?>
                      </div>
                    </div>

                    <?php if (!empty($row['payment_id']) && ($hs === 'approved' || $hs === 'paid')): ?>
                      <a class="btn btn-sm btn-outline-secondary"
                         href="/artovue/dashboard/student/student_invoice.php?payment_id=<?= (int)$row['payment_id'] ?>">
                         Download Invoice
                      </a>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>

        <!-- Upcoming (unchanged visual, shows window hint for first item) -->
        <div class="card shadow-sm border-0">
          <div class="card-header bg-white border-0">
            <h5 class="mb-0">Upcoming</h5>
            <small class="text-muted">Next <?= (int)max(0, count((array)$upcoming)) ?> cycle<?= count((array)$upcoming)===1?'':'s' ?></small>
          </div>
          <div class="card-body">
            <?php if (empty($upcoming)): ?>
              <p class="mb-0 text-muted">No future cycles to show.</p>
            <?php else: ?>
              <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $index => $u): ?>
                  <?php $us = strtolower($u['status'] ?? ''); ?>
                  <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                    <div>
                      <div><strong>Month:</strong> <?= htmlspecialchars($u['label'] ?? '') ?></div>
                      <div class="text-muted">
                        Status:
                        <?php
                          if ($us === 'approved' || $us === 'paid') {
                              echo badge('Paid', 'bg-success');
                          } elseif ($us === 'pending') {
                              echo badge('Pending', 'bg-warning text-dark');
                          } elseif ($us === 'covered') {
                              echo badge('Covered', 'bg-info text-dark');
                          } else {
                              echo badge('Expected', 'bg-secondary');
                          }
                        ?>
                      </div>
                      <?php if ($index === 0 && !empty($window['start_label']) && !empty($window['end_label'])): ?>
                        <div class="text-muted small mt-1">
                          Upload window: <?= htmlspecialchars($window['start_label']) ?> → <?= htmlspecialchars($window['end_label']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($u['payment_id']) && strtolower($u['status'] ?? '') === 'paid'): ?>
                      <a class="btn btn-sm btn-outline-secondary"
                         href="/artovue/dashboard/student/student_invoice.php?payment_id=<?= (int)$u['payment_id'] ?>">
                         Invoice
                      </a>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
