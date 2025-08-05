<?php
// File: dashboard/admin/payment_plans.php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../helpers/functions.php';  // set_flash(), get_flash()
require_once __DIR__ . '/../../config/db.php';

$flash    = get_flash();
$editPlan = null;

// ─── Handle Add / Edit ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id              = (int) ($_POST['id']              ?? 0);
    $centre_id       = (int) ($_POST['centre_id']       ?? 0);
    $art_group_id    = (int) ($_POST['art_group_id']    ?? 0);
    $plan_name       = trim($_POST['plan_name']         ?? '');
    $duration_months = (int) ($_POST['duration_months'] ?? 0);
    $amount          = (float) ($_POST['amount']        ?? 0);
    $enrollment_fee  = (float) ($_POST['enrollment_fee'] ?? 0);
    $advance_fee     = (float) ($_POST['advance_fee']    ?? 0);
    $late_fee        = (float) ($_POST['late_fee']       ?? 0);
    $gst_percent     = (float) ($_POST['gst_percent']    ?? 0);
    $prorate_allowed = isset($_POST['prorate_allowed']) ? 1 : 0;

    if ($centre_id && $art_group_id && $plan_name && $duration_months && $amount) {
        if ($id > 0) {
            // — UPDATE existing plan —
            $stmt = $conn->prepare("
                UPDATE payment_plans
                   SET centre_id       = ?,
                       art_group_id    = ?,
                       plan_name       = ?,
                       duration_months = ?,
                       amount          = ?,
                       enrollment_fee  = ?,
                       advance_fee     = ?,
                       late_fee        = ?,
                       gst_percent     = ?,
                       prorate_allowed = ?
                 WHERE id = ?
            ");
            //                       1    2    3    4    5    6    7    8    9    10   11
            $stmt->bind_param(
                'iisidddddii',
                 $centre_id,
                 $art_group_id,
                 $plan_name,
                 $duration_months,
                 $amount,
                 $enrollment_fee,
                 $advance_fee,
                 $late_fee,
                 $gst_percent,
                 $prorate_allowed,
                 $id
            );

            try {
                $stmt->execute();
                set_flash('Plan updated.', 'success');
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    set_flash('That plan already exists for this centre/group/duration.', 'danger');
                } else {
                    throw $e;
                }
            }
            $stmt->close();
        } else {
            // — INSERT new plan —
            $stmt = $conn->prepare("
                INSERT INTO payment_plans
                  (centre_id, art_group_id, plan_name, duration_months, amount,
                   enrollment_fee, advance_fee, late_fee, gst_percent, prorate_allowed)
                VALUES (?,         ?,            ?,         ?,               ?,
                        ?,              ?,            ?,         ?,            ?)
            ");
            //             1    2    3    4    5    6    7    8    9    10
            $stmt->bind_param(
                'iisidddddi',
                 $centre_id,
                 $art_group_id,
                 $plan_name,
                 $duration_months,
                 $amount,
                 $enrollment_fee,
                 $advance_fee,
                 $late_fee,
                 $gst_percent,
                 $prorate_allowed
            );

            try {
                $stmt->execute();
                set_flash('New plan added.', 'success');
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() === 1062) {
                    set_flash('That plan already exists for this centre/group/duration.', 'danger');
                } else {
                    throw $e;
                }
            }
            $stmt->close();
        }
    } else {
        set_flash('Please fill all required fields.', 'danger');
    }

    header('Location: ?page=payment_plans');
    exit;
}

// ─── Handle Delete ───────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $id   = (int)$_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM payment_plans WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    set_flash('Plan deleted.', 'success');
    header('Location: ?page=payment_plans');
    exit;
}

// ─── Load for Edit if requested ─────────────────────────────────
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM payment_plans WHERE id = ?");
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 1) {
        $editPlan = $res->fetch_assoc();
    }
    $stmt->close();
}

// ─── Fetch Centres, Groups & Existing Plans ─────────────────────
$centres   = $conn->query("SELECT id,name FROM centres ORDER BY name")
                  ->fetch_all(MYSQLI_ASSOC);
$groups    = $conn->query("SELECT id,label FROM art_groups ORDER BY sort_order, label")
                  ->fetch_all(MYSQLI_ASSOC);

$filterCentre = (int)($_GET['filter_centre'] ?? 0);
$filterGroup  = (int)($_GET['filter_group']  ?? 0);

$sql = "
    SELECT p.*, c.name AS centre, g.label AS group_label
      FROM payment_plans p
      JOIN centres     c ON c.id = p.centre_id
      JOIN art_groups  g ON g.id = p.art_group_id
";
$clauses = [];
if ($filterCentre) $clauses[] = "p.centre_id = {$filterCentre}";
if ($filterGroup)  $clauses[] = "p.art_group_id = {$filterGroup}";
if ($clauses) {
    $sql .= ' WHERE ' . implode(' AND ', $clauses);
}
$sql .= " ORDER BY c.name, g.sort_order, p.duration_months";

$plans = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Payment Plans</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"/>
  <style>
    body { background:#f8f9fa; }
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill,minmax(200px,1fr));
      gap: 1rem;
    }
  </style>
</head>
<body>
  <div class="container py-5">

    <h2 class="mb-4"><i class="bi bi-currency-dollar"></i> Payment Plans</h2>

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type']==='danger'?'danger':'success' ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Add / Edit Form -->
    <form method="post" class="mb-4 form-grid">
      <input type="hidden" name="id" value="<?= $editPlan['id'] ?? '' ?>">

      <select name="centre_id" required class="form-select">
        <option value="">Select Centre</option>
        <?php foreach($centres as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= ($editPlan['centre_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="art_group_id" required class="form-select">
        <option value="">Select Group</option>
        <?php foreach($groups as $g): ?>
          <option value="<?= $g['id'] ?>"
            <?= ($editPlan['art_group_id'] ?? '') == $g['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($g['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <input type="text" name="plan_name" required
             placeholder="Plan name"
             value="<?= htmlspecialchars($editPlan['plan_name'] ?? '') ?>"
             class="form-control"/>

      <input type="number" name="duration_months" required
             placeholder="Months"
             value="<?= htmlspecialchars($editPlan['duration_months'] ?? '') ?>"
             class="form-control"/>

      <input type="number" step="0.01" name="amount" required
             placeholder="₹ Amount"
             value="<?= htmlspecialchars($editPlan['amount'] ?? '') ?>"
             class="form-control"/>

      <input type="number" step="0.01" name="enrollment_fee"
             placeholder="Enroll fee"
             value="<?= htmlspecialchars($editPlan['enrollment_fee'] ?? '') ?>"
             class="form-control"/>

      <input type="number" step="0.01" name="advance_fee"
             placeholder="Advance fee"
             value="<?= htmlspecialchars($editPlan['advance_fee'] ?? '') ?>"
             class="form-control"/>

      <input type="number" step="0.01" name="late_fee"
             placeholder="Late fee"
             value="<?= htmlspecialchars($editPlan['late_fee'] ?? '') ?>"
             class="form-control"/>

      <input type="number" step="0.01" name="gst_percent"
             placeholder="GST %"
             value="<?= htmlspecialchars($editPlan['gst_percent'] ?? '') ?>"
             class="form-control"/>

      <label class="d-flex align-items-center">
        <input type="checkbox" name="prorate_allowed" value="1"
          <?= !empty($editPlan['prorate_allowed']) ? 'checked' : '' ?>>
        <span class="ms-2">Allow Proration</span>
      </label>

      <button type="submit" class="btn btn-primary">
        <?= $editPlan ? 'Update Plan' : 'Add Plan' ?>
      </button>
    </form>

    <!-- Centre & Group Filter -->
    <form method="get" class="mb-3 d-flex gap-2">
      <input type="hidden" name="page" value="payment_plans">
      <select name="filter_centre" class="form-select w-auto">
        <option value="">All Centres</option>
        <?php foreach($centres as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= $filterCentre == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="filter_group" class="form-select w-auto">
        <option value="">All Groups</option>
        <?php foreach($groups as $g): ?>
          <option value="<?= $g['id'] ?>"
            <?= $filterGroup == $g['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($g['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary">Filter</button>
    </form>

    <!-- Plans Table -->
    <div class="table-responsive">
      <table class="table table-striped align-middle">
        <thead class="table-light">
          <tr>
            <th>Centre</th>
            <th>Group</th>
            <th>Plan</th>
            <th class="text-center">Duration</th>
            <th class="text-end">Amount</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($plans)): ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-4">
                No plans found.
              </td>
            </tr>
          <?php else: foreach($plans as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['centre']) ?></td>
              <td><?= htmlspecialchars($p['group_label']) ?></td>
              <td><?= htmlspecialchars($p['plan_name']) ?></td>
              <td class="text-center"><?= (int)$p['duration_months'] ?> mo</td>
              <td class="text-end">₹<?= number_format($p['amount'],2) ?></td>
              <td class="text-center">
                <a href="?page=payment_plans&edit=<?= $p['id'] ?>"
                   class="btn btn-sm btn-outline-primary">Edit</a>
                <a href="?page=payment_plans&delete=<?= $p['id'] ?>"
                   class="btn btn-sm btn-outline-danger"
                   onclick="return confirm('Delete this plan?')">
                   Delete
                </a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
