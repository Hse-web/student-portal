<?php
// File: dashboard/admin/comp_requests.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// 0) Map slot codes to human labels
$slotLabels = [
  'sun_10_12'  => 'Sunday 4 PM – 6 PM',
  'sat_11_130' => 'Saturday 11:30 AM – 1:30 PM',
  'wed_17_19'  => 'Wednesday 5 PM – 7 PM',
  'mon_17_19'  => 'Monday 5 PM – 7 PM',
];

// 1) Read & normalize date filters (accept both yyyy-mm-dd and dd/mm/yyyy)
function normalizeDate($in) {
  if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#',$in,$m)) {
    return "{$m[3]}-{$m[2]}-{$m[1]}";
  }
  return preg_match('/^\d{4}-\d{2}-\d{2}$/',$in) ? $in : '';
}
$startRaw = $_GET['start']   ?? '';
$endRaw   = $_GET['end']     ?? '';
$start    = normalizeDate($startRaw);
$end      = normalizeDate($endRaw);

$status   = $_GET['status']  ?? '';
$student  = $_GET['student'] ?? '';
if (!in_array($status,['approved','missed'],true)) $status = '';

// 2) Build WHERE clauses
$where = []; $params = []; $types = '';
if ($start) {
  $where[]   = 'c.requested_at >= ?'; 
  $types   .= 's'; 
  $params[] = $start.' 00:00:00';
}
if ($end) {
  $where[]   = 'c.requested_at <= ?'; 
  $types   .= 's'; 
  $params[] = $end.' 23:59:59';
}
if ($status) {
  $where[]   = 'c.status = ?'; 
  $types   .= 's'; 
  $params[] = $status;
}
if ($student) {
  $where[]   = 's.name LIKE ?'; 
  $types   .= 's'; 
  $params[] = "%{$student}%";
}
$whereSql = $where ? 'WHERE '.implode(' AND ',$where) : '';

// 3) Summary counts
$summary = ['total'=>0,'approved'=>0,'missed'=>0];
$sql = "
  SELECT c.status, COUNT(*) AS cnt
    FROM compensation_requests c
    JOIN students s ON s.user_id = c.user_id
    $whereSql
   GROUP BY c.status
";

$stmt = $conn->prepare($sql);
if ($where) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) {
  $summary['total']        += $r['cnt'];
  $summary[$r['status']]    = $r['cnt'];
}
$stmt->close();

// 4) Fetch detail rows
$sql = "
  SELECT
    c.id,
    s.name AS student_name,
    DATE_FORMAT(c.absent_date,'%Y-%m-%d') AS absent_date,
    DATE_FORMAT(c.comp_date,'%Y-%m-%d')   AS comp_date,
    c.slot,
    DATE_FORMAT(c.requested_at,'%Y-%m-%d %h:%i %p') AS requested_at,
    c.status
  FROM compensation_requests c
  JOIN students        s ON s.user_id = c.user_id
  $whereSql
  ORDER BY c.requested_at DESC
  LIMIT 1000
";
$stmt = $conn->prepare($sql);
if ($where) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 5) Flash messages + today marker
$flashS = $_SESSION['flash_success'] ?? '';
$flashE = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'],$_SESSION['flash_error']);
$today = new DateTimeImmutable();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Compensation Requests</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"/>
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2>Compensation Requests</h2>
    <?php if($flashS): ?>
      <div class="alert alert-success"><?= htmlspecialchars($flashS) ?></div>
    <?php elseif($flashE): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($flashE) ?></div>
    <?php endif; ?>

    <!-- summary -->
    <div class="row mb-4">
      <?php foreach(['total'=>'Total','approved'=>'Approved','missed'=>'Missed'] as $k=>$lbl): ?>
        <div class="col-sm-4">
          <div class="card text-center mb-3">
            <div class="card-body">
              <h6><?= $lbl ?> Requests</h6>
              <p class="display-6 mb-0"><?= $summary[$k] ?></p>
            </div>
          </div>
        </div>
      <?php endforeach;?>
    </div>

    <!-- filters -->
    <form method="get" class="row g-3 mb-5">
      <input type="hidden" name="page" value="comp_requests">
      <div class="col-md-3">
        <label>From</label>
        <input type="date" name="start" value="<?= htmlspecialchars($start) ?>"
               class="form-control">
      </div>
      <div class="col-md-3">
        <label>To</label>
        <input type="date" name="end" value="<?= htmlspecialchars($end) ?>"
               class="form-control">
      </div>
      <div class="col-md-2">
        <label>Status</label>
        <select name="status" class="form-select">
          <option value="">All</option>
          <option value="approved" <?= $status==='approved'?'selected':''?>>
            Approved
          </option>
          <option value="missed" <?= $status==='missed'?'selected':''?>>
            Missed
          </option>
        </select>
      </div>
      <div class="col-md-3">
        <label>Student</label>
        <input type="text" name="student" value="<?= htmlspecialchars($student) ?>"
               class="form-control">
      </div>
      <div class="col-md-1 d-grid">
        <label>&nbsp;</label>
        <button class="btn btn-primary">Apply</button>
      </div>
    </form>

    <!-- table -->
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Student</th>
            <th>Absent</th>
            <th>Make-Up</th>
            <th>Slot</th>
            <th>Requested At</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="8" class="text-center py-4">No requests found.</td>
            </tr>
          <?php else: foreach($rows as $r):
            $cd = DateTimeImmutable::createFromFormat('Y-m-d',$r['comp_date']);
          ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td><?= htmlspecialchars($r['student_name']) ?></td>
              <td><?= $r['absent_date'] ?></td>
              <td><?= $r['comp_date'] ?></td>
              <td>
                <?= htmlspecialchars(
                     $slotLabels[$r['slot']] 
                     ?? str_replace('_',' ',$r['slot'])
                   ) ?>
              </td>
              <td><?= $r['requested_at'] ?></td>
              <td>
                <span class="badge bg-<?= $r['status']==='approved'?'success':'danger' ?>">
                  <?= ucfirst($r['status']) ?>
                </span>
              </td>
              <td>
                <?php if($r['status']==='approved'): ?>
                  <?php if($cd < $today): ?>
                    <button class="btn btn-sm btn-secondary" disabled>
                      Window Passed
                    </button>
                  <?php else: ?>
                    <a href="mark_missed.php?id=<?=$r['id']?>" 
                       class="btn btn-sm btn-danger">
                      Mark Missed
                    </a>
                  <?php endif; ?>
                <?php else: ?>
                  <button class="btn btn-sm btn-success"
                          data-bs-toggle="modal"
                          data-bs-target="#reapproveModal"
                          data-request-id="<?=$r['id']?>"
                          <?= $cd < $today ? 'disabled':'' ?>>
                    Re-Approve
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- re-approve modal (same as before) -->
  <div class="modal fade" id="reapproveModal" tabindex="-1">
    <div class="modal-dialog">
      <form id="reapproveForm" class="modal-content" method="POST" action="reapprove.php">
        <input type="hidden" name="id" value="">
        <div class="modal-header">
          <h5 class="modal-title">Re-Approve Make-Up</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label>New Make-Up Date</label>
            <input type="date" name="comp_date" class="form-control" required>
          </div>
          <div class="mb-3">
            <label>Slot</label>
            <select name="slot" class="form-select" required>
              <?php foreach($slotLabels as $code=>$lbl): ?>
                <option value="<?= htmlspecialchars($code) ?>">
                  <?= htmlspecialchars($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    document
      .getElementById('reapproveModal')
      .addEventListener('show.bs.modal', e => {
        document.querySelector('#reapproveForm input[name=id]')
                .value = e.relatedTarget.getAttribute('data-request-id');
      });
  </script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
