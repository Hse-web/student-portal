<?php
// File: dashboard/admin/admin_payment.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── Generate a CSRF token for all POST actions (approve/reject) ─────
$csrf = generate_csrf_token();

// ─── 0) Read current filter values from GET ──────────────────────────
$searchTerm   = trim($_GET['search']   ?? '');
$centreFilter = (int) ($_GET['centre'] ?? 0);
$planFilter   = (int) ($_GET['plan']   ?? 0);
$statusFilter = trim($_GET['status']  ?? '');

// ─── 1) Load all Centres and Plans for the dropdowns ────────────────
$centreOptions = $conn
    ->query("SELECT id, name FROM centres ORDER BY name")
    ->fetch_all(MYSQLI_ASSOC);

$planOptions = $conn
    ->query("SELECT id, plan_name FROM payment_plans ORDER BY plan_name")
    ->fetch_all(MYSQLI_ASSOC);

// ─── 2) Build the base SQL (join payment_proofs → students → subscriptions → plans) ──
$sql = "
  SELECT
    pp.id               AS proof_id,
    pp.student_id,
    pp.file_path,
    pp.uploaded_at,
    pp.payment_method,
    pp.txn_id,
    pp.amount          AS proof_amount,
    pp.status          AS proof_status,
    s.name             AS student_name,
    s.email            AS student_email,
    s.group_name       AS group_name,
    s.centre_id,
    ss.plan_id,
    p.plan_name
  FROM payment_proofs pp
  JOIN students            s  ON s.id = pp.student_id
  LEFT JOIN student_subscriptions ss
                         ON ss.student_id = s.id
                         AND ss.id = (
                              SELECT id 
                                FROM student_subscriptions 
                               WHERE student_id = s.id
                               ORDER BY subscribed_at DESC 
                               LIMIT 1
                           )
  LEFT JOIN payment_plans  p  ON p.id = ss.plan_id
  WHERE 1=1
";

$params     = [];
$paramTypes = '';

// ─── 3) Append dynamic WHERE clauses if filters are set ─────────────
if ($searchTerm !== '') {
    $sql .= " AND (
                s.name       LIKE ?
             OR s.email      LIKE ?
             OR s.group_name LIKE ?
            )";
    $wild = "%{$searchTerm}%";
    $paramTypes .= 'sss';
    $params[] = $wild;
    $params[] = $wild;
    $params[] = $wild;
}

if ($centreFilter > 0) {
    $sql .= " AND s.centre_id = ?";
    $paramTypes .= 'i';
    $params[] = $centreFilter;
}

if ($planFilter > 0) {
    $sql .= " AND ss.plan_id = ?";
    $paramTypes .= 'i';
    $params[] = $planFilter;
}

if (in_array($statusFilter, ['Pending','Approved','Rejected'], true)) {
    $sql .= " AND pp.status = ?";
    $paramTypes .= 's';
    $params[] = $statusFilter;
}

$sql .= " ORDER BY pp.uploaded_at DESC";

// ─── 4) Prepare & execute the filtered query ────────────────────────
$stmt = $conn->prepare($sql);
if ($paramTypes !== '') {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── 5) Check for flash (success/failure) messages in the URL ───────
$flashMsg = '';
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'approved') {
        $flashMsg = '<div class="alert alert-success">Payment proof approved successfully.</div>';
    } elseif ($_GET['msg'] === 'rejected') {
        $flashMsg = '<div class="alert alert-danger">Payment proof rejected.</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin – Manage Payment Proofs</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5 CSS (CDN) -->
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <style>
    /* Tiny spacing adjustment for the filter row */
    .filter-form .form-control { max-width: 240px; }
    .filter-form .btn { margin-left: 0.5rem; }
  </style>
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2 class="mb-4">💰 Manage Payment Proofs (All Statuses)</h2>

    <!-- ─── Filter Bar ───────────────────────────────────────────────── -->
    <form
      method="get"
      action="index.php?page=admin_payment"
      class="row g-2 align-items-center filter-form mb-4"
    >
      <!-- Search by name/email/group -->
      <div class="col-auto">
        <input 
          type="text" 
          name="search" 
          value="<?= htmlspecialchars($searchTerm) ?>" 
          class="form-control form-control-sm" 
          placeholder="Search name, email or group..."
        >
      </div>

      <!-- Centre dropdown -->
      <div class="col-auto">
        <select name="centre" class="form-select form-select-sm">
          <option value="0" <?= $centreFilter === 0 ? 'selected' : '' ?>>All Centres</option>
          <?php foreach ($centreOptions as $c): ?>
            <option 
              value="<?= (int)$c['id'] ?>" 
              <?= $centreFilter === (int)$c['id'] ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Plan dropdown -->
      <div class="col-auto">
        <select name="plan" class="form-select form-select-sm">
          <option value="0" <?= $planFilter === 0 ? 'selected' : '' ?>>All Plans</option>
          <?php foreach ($planOptions as $p): ?>
            <option 
              value="<?= (int)$p['id'] ?>" 
              <?= $planFilter === (int)$p['id'] ? 'selected' : '' ?>
            >
              <?= htmlspecialchars($p['plan_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Status dropdown -->
      <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
          <option value=""  <?= $statusFilter === '' ? 'selected' : '' ?>>All Statuses</option>
          <option value="Pending"  <?= $statusFilter === 'Pending'  ? 'selected' : '' ?>>Pending</option>
          <option value="Approved" <?= $statusFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
          <option value="Rejected" <?= $statusFilter === 'Rejected' ? 'selected' : '' ?>>Rejected</option>
        </select>
      </div>

      <!-- Filter button -->
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
      </div>

      <!-- Export links (carry filters forward) -->
      <div class="col-auto">
        <?php 
          // Build query string from current filters so exports include them:
          $qs = http_build_query([
            'search' => $searchTerm,
            'centre' => $centreFilter,
            'plan'   => $planFilter,
            'status' => $statusFilter,
          ]);
        ?>
        <a 
          href="admin_export_payments.php?format=csv&<?= $qs ?>" 
          class="btn btn-outline-secondary btn-sm"
        >Export CSV</a>
        <a 
          href="admin_export_payments.php?format=pdf&<?= $qs ?>" 
          class="btn btn-outline-secondary btn-sm"
        >Export PDF</a>
      </div>
    </form>

    <!-- ─── Flash Message (if any) ──────────────────────────────────── -->
    <?= $flashMsg ?>

    <!-- ─── Table of Payment Proofs ─────────────────────────────────── -->
    <div class="table-responsive">
      <table class="table table-striped table-bordered">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Student</th>
            <th>Email</th>
            <th>Group</th>
            <th>Centre</th>
            <th>Plan</th>
            <th>Status</th>
            <th class="text-end">Amount (₹)</th>
            <th>Uploaded At</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted">
                No payment proofs found.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($payments as $row): ?>
              <tr>
                <td><?= htmlspecialchars($row['proof_id']) ?></td>
                <td><?= htmlspecialchars($row['student_name']) ?></td>
                <td><?= htmlspecialchars($row['student_email']) ?></td>
                <td><?= htmlspecialchars($row['group_name']) ?></td>
                <td>
                  <?php
                    // Lookup centre name from $centreOptions
                    $centreName = '—';
                    foreach ($centreOptions as $c) {
                      if ((int)$c['id'] === (int)$row['centre_id']) {
                        $centreName = htmlspecialchars($c['name']);
                        break;
                      }
                    }
                    echo $centreName;
                  ?>
                </td>
                <td><?= htmlspecialchars($row['plan_name'] ?: '—') ?></td>
                <td>
                  <?php if ($row['proof_status'] === 'Pending'): ?>
                    <span class="badge bg-warning">Pending</span>
                  <?php elseif ($row['proof_status'] === 'Approved'): ?>
                    <span class="badge bg-success">Approved</span>
                  <?php else: /* Rejected */ ?>
                    <span class="badge bg-danger">Rejected</span>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= number_format($row['proof_amount'], 2) ?></td>
                <td>
                  <?= htmlspecialchars($row['uploaded_at']) ?><br>
                  <small>
                    <a
                      href="../uploads/payment_proofs/<?= rawurlencode(basename($row['file_path'])) ?>"
                      target="_blank"
                    >View File</a>
                  </small>
                </td>
                <td class="text-center">
                  <?php if ($row['proof_status'] === 'Pending'): ?>
                    <!-- APPROVE FORM -->
                    <form
                      method="post"
                      action="../../actions/admin_handle_proof.php"
                      class="d-inline-block mb-1"
                    >
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="id"     value="<?= (int)$row['proof_id'] ?>">
                      <input type="hidden" name="action" value="approve">
                      <button
                        type="submit"
                        class="btn btn-sm btn-success"
                        title="Approve Proof"
                      ><i class="bi bi-check-lg"></i></button>
                    </form>

                    <!-- REJECT FORM (with reason) -->
                    <form
                      method="post"
                      action="../../actions/admin_handle_proof.php"
                      class="d-inline-block"
                    >
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="id"     value="<?= (int)$row['proof_id'] ?>">
                      <input type="hidden" name="action" value="reject">
                      <div class="input-group input-group-sm">
                        <input
                          type="text"
                          name="rejection_reason"
                          class="form-control"
                          placeholder="Reason"
                          required
                        >
                        <button
                          type="submit"
                          class="btn btn-sm btn-danger"
                          title="Reject Proof"
                        ><i class="bi bi-x-lg"></i></button>
                      </div>
                    </form>
                  <?php else: ?>
                    &mdash;
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Bootstrap 5 JS Bundle (for icons/JS) -->
  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
  ></script>
</body>
</html>
