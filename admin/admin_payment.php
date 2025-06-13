<?php
// File: dashboard/admin/admin_payment.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

$csrf = generate_csrf_token();

// 0) Filters
$searchTerm   = trim($_GET['search']   ?? '');
$centreFilter = (int)($_GET['centre'] ?? 0);
$planFilter   = (int)($_GET['plan']   ?? 0);
$statusFilter = trim($_GET['status']  ?? '');

// 1) Dropdowns
$centreOptions = $conn
  ->query("SELECT id,name FROM centres ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$planOptions = $conn
  ->query("SELECT id,plan_name FROM payment_plans ORDER BY plan_name")
  ->fetch_all(MYSQLI_ASSOC);

// 2) Base SQL (with centre join)
$sql = "
  SELECT
    pp.id           AS proof_id,
    pp.student_id,
    pp.file_path,
    pp.uploaded_at,
    pp.amount       AS proof_amount,
    pp.status       AS proof_status,
    s.name          AS student_name,
    s.email         AS student_email,
    s.group_name    AS group_name,
    c.name          AS centre_name,
    p.plan_name
  FROM payment_proofs pp
  JOIN students   s ON s.id = pp.student_id
  LEFT JOIN centres          c  ON c.id  = s.centre_id
  LEFT JOIN student_subscriptions ss
    ON ss.student_id = s.id
    AND ss.id = (
      SELECT id 
        FROM student_subscriptions
       WHERE student_id = s.id
       ORDER BY subscribed_at DESC
       LIMIT 1
    )
  LEFT JOIN payment_plans    p  ON p.id  = ss.plan_id
  WHERE 1=1
";

$params     = [];
$paramTypes = '';

// 3) Filters
if ($searchTerm!=='') {
  $sql        .= " AND (s.name LIKE ? OR s.email LIKE ? OR s.group_name LIKE ?)";
  $wild        = "%{$searchTerm}%";
  $paramTypes .= 'sss';
  $params     = [$wild,$wild,$wild];
}
if ($centreFilter>0) {
  $sql        .= " AND s.centre_id = ?";
  $paramTypes .= 'i';
  $params[]    = $centreFilter;
}
if ($planFilter>0) {
  $sql        .= " AND p.id = ?";
  $paramTypes .= 'i';
  $params[]    = $planFilter;
}
if (in_array($statusFilter,['Pending','Approved','Rejected'],true)) {
  $sql        .= " AND pp.status = ?";
  $paramTypes .= 's';
  $params[]    = $statusFilter;
}
$sql .= " ORDER BY pp.uploaded_at DESC";

// 4) Execute
$stmt = $conn->prepare($sql);
if ($paramTypes) {
  $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5) Flash
$flash = '';
if (($_GET['msg'] ?? '')==='approved') {
  $flash = '<div class="alert alert-success">Payment proof approved.</div>';
}
if (($_GET['msg'] ?? '')==='rejected') {
  $flash = '<div class="alert alert-danger">Payment proof rejected.</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin – Manage Payment Proofs</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" 
    rel="stylesheet"
  >
  <style>
    .visually-hidden {
      position:absolute!important;width:1px;height:1px;overflow:hidden;
      clip:rect(0,0,0,0);white-space:nowrap;border:0;
    }
  </style>
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2 class="mb-4">💰 Payment Proofs</h2>

    <!-- Filters -->
    <form method="get" class="row gy-2 gx-3 align-items-center mb-4">
      <div class="col-md-4">
        <label for="search" class="visually-hidden">Search</label>
        <input type="text" id="search" name="search"
               class="form-control form-control-sm"
               placeholder="Name, email or group…"
               value="<?= htmlspecialchars($searchTerm) ?>">
      </div>
      <div class="col-md-2">
        <label for="centre" class="visually-hidden">Centre</label>
        <select id="centre" name="centre" class="form-select form-select-sm">
          <option value="0">All Centres</option>
          <?php foreach($centreOptions as $c): ?>
            <option value="<?= $c['id'] ?>"
              <?= $centreFilter===$c['id']?'selected':'' ?>>
              <?= htmlspecialchars($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="plan" class="visually-hidden">Plan</label>
        <select id="plan" name="plan" class="form-select form-select-sm">
          <option value="0">All Plans</option>
          <?php foreach($planOptions as $p): ?>
            <option value="<?= $p['id'] ?>"
              <?= $planFilter===$p['id']?'selected':'' ?>>
              <?= htmlspecialchars($p['plan_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="status" class="visually-hidden">Status</label>
        <select id="status" name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option <?= $statusFilter==='Pending'  ?'selected':'' ?>>Pending</option>
          <option <?= $statusFilter==='Approved' ?'selected':'' ?>>Approved</option>
          <option <?= $statusFilter==='Rejected' ?'selected':'' ?>>Rejected</option>
        </select>
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-sm btn-primary">Filter</button>
      </div>
    </form>

    <?= $flash ?>

    <div class="table-responsive">
      <table class="table table-striped table-borderless align-middle">
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
            <th>Uploaded At (IST)</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted py-4">
                No payment proofs found.
              </td>
            </tr>
          <?php else: foreach($payments as $r): ?>
            <tr>
              <td><?= (int)$r['proof_id'] ?></td>
              <td><?= htmlspecialchars($r['student_name']) ?></td>
              <td><?= htmlspecialchars($r['student_email']) ?></td>
              <td><?= htmlspecialchars($r['group_name']) ?></td>
              <td><?= htmlspecialchars($r['centre_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars($r['plan_name'] ?? '—') ?></td>
              <td>
                <?php if ($r['proof_status']==='Pending'): ?>
                  <span class="badge bg-warning text-dark">Pending</span>
                <?php elseif ($r['proof_status']==='Approved'): ?>
                  <span class="badge bg-success">Approved</span>
                <?php else: ?>
                  <span class="badge bg-danger">Rejected</span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= number_format($r['proof_amount'],2) ?></td>
              <td>
                <?php
                  $dt = new DateTime($r['uploaded_at'], new DateTimeZone('UTC'));
                  $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
                  echo $dt->format('Y-m-d H:i:s');
                ?>
                <br><small>
                  <a href="../uploads/payment_proofs/
                          <?= rawurlencode(basename($r['file_path'])) ?>"
                     target="_blank">View</a>
                </small>
              </td>
              <td class="text-center">
                <?php if ($r['proof_status']==='Pending'): ?>
                  <form method="post" action="../../actions/admin_handle_proof.php"
                        class="d-inline me-1">
                    <input type="hidden" name="_csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="id"     value="<?= (int)$r['proof_id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn btn-sm btn-success">
                      <i class="bi bi-check-lg"></i>
                    </button>
                  </form>
                  <form method="post" action="../../actions/admin_handle_proof.php"
                        class="d-inline">
                    <input type="hidden" name="_csrf"           value="<?= $csrf ?>">
                    <input type="hidden" name="id"              value="<?= (int)$r['proof_id'] ?>">
                    <input type="hidden" name="action"          value="reject">
                    <div class="input-group input-group-sm">
                      <input type="text" name="rejection_reason"
                             class="form-control" placeholder="Reason" required>
                      <button class="btn btn-sm btn-danger">
                        <i class="bi bi-x-lg"></i>
                      </button>
                    </div>
                  </form>
                <?php else: ?>
                  &mdash;
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script 
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
