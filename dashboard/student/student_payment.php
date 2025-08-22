<?php
// File: dashboard/student/student_payment.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// (We'll reuse your helper that finds the student's current group label)
require_once __DIR__ . '/../helpers/functions.php';

$studentId = intval($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
  header('Location:/artovue/login.php');
  exit;
}

function inr($n){ return number_format((float)$n, 2, '.', ','); }
const GST_FALLBACK = 18.0; // % — used only to split base & GST visually

// --- tiny helpers -----------------------------------------------------------

/** Try to infer duration months (1/3/6) from free text. */
function infer_months(string $txt): int {
  $t = strtolower($txt);
  if (preg_match('/\bmonthly\b|\b1\s*month\b|\b1m\b/', $t)) return 1;
  if (preg_match('/\b(3\s*m(onths?)?|quarter(ly)?)\b/', $t)) return 3;
  if (preg_match('/\b(6\s*m(onths?)?|half[-\s]?year|semi[-\s]?annual)\b/', $t)) return 6;
  if (preg_match('/\b([136])\s*m\b/', $t, $m)) return max(1, (int)$m[1]);
  return 1; // default safest
}

/** Strip obvious noise (paths, timestamps, long numeric blobs) from a label. */
function sanitize_label(string $s): string {
  // kill paths
  if (preg_match('#/|\\\\#', $s)) $s = preg_replace('#\S*/#', '', $s);
  // remove long digit blobs (likely IDs/timestamps)
  $s = preg_replace('/\b\d{6,}\b/', '', $s);
  // remove excessive spaces & “error” leftovers
  $s = str_ireplace(['error', 'uploads', 'payment_proofs'], '', $s);
  $s = trim(preg_replace('/\s+/', ' ', $s));
  return $s;
}

/** Pick a decent display field from a proof row if present. */
function pick_proof_label(array $row): string {
  $candidates = [
    'plan_label','plan_name','plan','program','course_name','group_name',
    'remarks','remark','notes','note','description','title'
  ];
  foreach ($candidates as $c) {
    if (isset($row[$c]) && is_string($row[$c]) && trim($row[$c]) !== '') {
      return sanitize_label($row[$c]);
    }
  }
  // Fallback: concatenate a few likely fields and sanitize
  $parts = [];
  foreach (['payment_mode','mode','txn_mode','method','status'] as $c) {
    if (!empty($row[$c]) && is_string($row[$c])) $parts[] = $row[$c];
  }
  return $parts ? sanitize_label(implode(' ', $parts)) : '';
}

// --- 1) newest payment for this student ------------------------------------

$sql = "
  SELECT
      p.payment_id,
      STR_TO_DATE(DATE_FORMAT(COALESCE(p.due_date, p.paid_at, p.created_at), '%Y-%m-01'), '%Y-%m-%d') AS anchor_month,
      p.due_date,
      p.paid_at,
      p.amount_due,
      p.amount_paid,
      p.created_at
  FROM payments p
  WHERE p.student_id = ?
  ORDER BY COALESCE(p.paid_at, p.due_date, p.created_at) DESC, p.payment_id DESC
  LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $studentId);
$stmt->execute();
$pay = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();

$anchorMonth = $pay['anchor_month'] ?? null;
$paymentId   = $pay['payment_id'] ?? null;

// --- 2) newest Approved proof for that payment (robust to schema) ----------

$proof     = [];
$proofId   = null;
$proofAmt  = null;
$proofText = '';

if ($paymentId) {
  $cols = [];
  if ($res = $conn->query("SHOW COLUMNS FROM payment_proofs")) {
    while ($c = $res->fetch_assoc()) $cols[] = $c['Field'];
    $res->free();
  }
  $orderCol = null;
  foreach (['proof_id','id','approved_at','created_at','uploaded_at'] as $cand) {
    if (in_array($cand, $cols, true)) { $orderCol = $cand; break; }
  }

  $q = "SELECT * FROM payment_proofs WHERE payment_id = ? AND status = 'Approved' ";
  if ($orderCol) $q .= "ORDER BY `$orderCol` DESC ";
  $q .= "LIMIT 1";

  $st = $conn->prepare($q);
  $st->bind_param('i', $paymentId);
  $st->execute();
  $proof = $st->get_result()->fetch_assoc() ?: [];
  $st->close();

  if ($proof) {
    $proofId   = $proof['proof_id'] ?? ($proof['id'] ?? null);
    $proofAmt  = isset($proof['amount']) ? (float)$proof['amount'] : null;
    $proofText = pick_proof_label($proof);
  }
}

// --- 3) months & next due ---------------------------------------------------

$months = $proofText !== '' ? infer_months($proofText) : 1;
$nextDue = $anchorMonth ? date('Y-m-d', strtotime("{$anchorMonth} +{$months} month")) : null;

// --- 4) amounts -------------------------------------------------------------

$gross = 0.0;
if ($proofAmt !== null) {
  $gross = $proofAmt;
} elseif (isset($pay['amount_due']) && $pay['amount_due'] !== null) {
  $gross = (float)$pay['amount_due'];
} elseif (isset($pay['amount_paid']) && $pay['amount_paid'] !== null) {
  $gross = (float)$pay['amount_paid'];
}

$base = $gross > 0 ? round($gross / (1 + GST_FALLBACK/100), 2) : 0.00;
$gst  = max(0.0, round($gross - $base, 2));

// --- 5) pretty Plan label ---------------------------------------------------

$groupLabel = '';
try {
  // your helper from /dashboard/helpers/functions.php
  $groupLabel = get_current_group_label($conn, $studentId) ?: '';
} catch (\Throwable $e) { /* ignore if helper not available */ }

$label = '';
if ($proofText !== '') {
  $label = $proofText;
}
// If proof label is empty or still looks like noise, synthesize a clean one:
if ($label === '' || strlen($label) < 3) {
  $label = ($groupLabel !== '' ? $groupLabel : 'Works') . ' — ' .
           ($months === 1 ? 'Monthly' : "{$months} months");
}

// --- 6) UI ------------------------------------------------------------------

$uploadOpens = $nextDue ? date('M j', strtotime(date('Y-m-01', strtotime($nextDue)))) : null;
$baseUrl = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'], 3)), '/');
?>
<div class="container-fluid px-4 py-6">
  <div class="bg-white rounded-xl shadow p-0 overflow-hidden" style="border:1px solid #eef;">
    <div style="background:#2563eb;color:#fff" class="px-5 py-3">
      <h3 class="m-0" style="font-weight:700">My Payment</h3>
    </div>

    <div class="row g-0 p-4 align-items-stretch">
      <div class="col-12 col-lg-8 pe-lg-4">
        <h5 class="mb-3" style="font-weight:700">Payment Overview</h5>

        <div class="mb-2">
          <div class="text-muted">Next Due:</div>
          <div style="font-size:1.05rem;font-weight:600;">
            <?= $nextDue ? htmlspecialchars(date('M j, Y', strtotime($nextDue))) : '—' ?>
          </div>
        </div>

        <div class="mb-2">
          <div class="text-muted">Plan:</div>
          <div style="font-size:1.05rem;font-weight:600;">
            <?= htmlspecialchars($label) ?>
          </div>
        </div>

        <div class="mb-3">
          <div class="text-muted">Duration:</div>
          <div style="font-size:1.05rem;font-weight:600;">
            <?= (int)$months ?> month(s)
          </div>
        </div>

        <hr>

        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-light border" disabled>
            <span class="me-2">≡</span>
            Upload opens on <?= $uploadOpens ? htmlspecialchars($uploadOpens) : '—' ?>
          </button>

          <?php if ($proofId): ?>
            <a class="btn btn-dark" href="<?= $baseUrl ?>/dashboard/student/student_invoice.php?proof_id=<?= urlencode((string)$proofId) ?>">
              ⬇️ Download last invoice
            </a>
          <?php else: ?>
            <button class="btn btn-dark" disabled>⬇️ Download last invoice</button>
          <?php endif; ?>
        </div>
      </div>

      <div class="col-12 col-lg-4">
        <div class="h-100 p-4 rounded" style="border:1px solid #eef;background:#fafcff">
          <div class="text-center mb-2" style="font-size:2rem;font-weight:800;color:#1d4ed8;">
            ₹<?= inr($gross) ?>
          </div>
          <div class="text-center text-muted">
            Base: ₹<?= inr($base) ?> • GST: ₹<?= inr($gst) ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
