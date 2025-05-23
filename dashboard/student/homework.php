<?php
// File: dashboard/student/homework.php

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';

// Only students
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '')!=='student') {
    header('Location: ../../login/index.php');
    exit;
}

$studentId = (int)($_SESSION['student_id'] ?? 0);

// ─── Fetch all assignments for this student ───────────────────────────
$stmt = $conn->prepare("
  SELECT
    id AS assignment_id,
    date_assigned,
    title,
    description,
    file_path
  FROM homework_assigned
  WHERE student_id = ?
  ORDER BY date_assigned DESC
");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Fetch submissions ───────────────────────────────────────────────
$stmt = $conn->prepare("
  SELECT assignment_id, file_path, submitted_at
    FROM homework_submissions
   WHERE student_id = ?
");
$stmt->bind_param('i',$studentId);
$stmt->execute();
$subs = [];
foreach($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $subs[$r['assignment_id']] = $r;
}
$stmt->close();

// ─── Classify & group ────────────────────────────────────────────────
$today = new DateTime();
$activeList   = [];
$archivedList = [];

foreach($all as $hw) {
  $dt = DateTime::createFromFormat('Y-m-d',$hw['date_assigned']);
  $diff = $dt->diff($today)->days;
  $locked  = $dt > $today;          // future assignments
  $expired = (!$locked && $diff > 90);
  $submitted = isset($subs[$hw['assignment_id']]);

  $hw['locked']     = $locked;
  $hw['expired']    = $expired;
  $hw['submission'] = $submitted ? $subs[$hw['assignment_id']] : null;

  if ($submitted || $expired) {
    $archivedList[] = $hw;
  } else {
    $activeList[] = $hw;
  }
}

// ─── Summary counts ────────────────────────────────────────────────
$counts = [
  'pending'   => count($activeList),
  'submitted' => 0,
  'expired'   => 0,
];
foreach($all as $hw) {
  if (isset($subs[$hw['assignment_id']])) $counts['submitted']++;
  else {
    $dt = new DateTime($hw['date_assigned']);
    if ($dt <= $today && $dt->diff($today)->days > 90) {
      $counts['expired']++;
    }
  }
}

// ─── Helper: group by Month Year ────────────────────────────────────
function groupByMonth(array $list) {
  $out = [];
  foreach($list as $hw) {
    $m = (new DateTime($hw['date_assigned']))->format('F Y');
    $out[$m][] = $hw;
  }
  return $out;
}
$activeGroups   = groupByMonth($activeList);
$archivedGroups = groupByMonth($archivedList);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Your Homework</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .section-header { margin:2rem 0 1rem; color:#d84315; font-weight:600; }
    .card-hover:hover { transform:translateY(-3px); box-shadow:0 4px 12px rgba(0,0,0,0.1); }
    .expired { opacity: .6; }
  </style>
</head>
<body>
  <div class="container py-4">

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <div class="card card-hover text-center p-3">
          <i class="bi bi-hourglass-split fs-1 text-warning"></i>
          <h5 class="mt-2">Pending</h5>
          <div class="display-5"><?= $counts['pending'] ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card card-hover text-center p-3">
          <i class="bi bi-check-circle fs-1 text-success"></i>
          <h5 class="mt-2">Submitted</h5>
          <div class="display-5"><?= $counts['submitted'] ?></div>
        </div>
      </div>
      <div class="col-md-4">
        <div class="card card-hover text-center p-3">
          <i class="bi bi-calendar-x fs-1 text-secondary"></i>
          <h5 class="mt-2">Expired</h5>
          <div class="display-5"><?= $counts['expired'] ?></div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-3" id="hwTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="active-tab" data-bs-toggle="tab"
                data-bs-target="#active" type="button" role="tab">
          Active
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" id="archived-tab" data-bs-toggle="tab"
                data-bs-target="#archived" type="button" role="tab">
          Archived
        </button>
      </li>
    </ul>
    <div class="tab-content" id="hwTabsContent">

      <!-- Active Tab -->
      <div class="tab-pane fade show active" id="active" role="tabpanel">
        <?php if (empty($activeGroups)): ?>
          <div class="alert alert-info">No active assignments.</div>
        <?php else: ?>
          <div class="accordion" id="accordionActive">
            <?php $i=0; foreach($activeGroups as $month=>$list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headingAct<?=$i?>">
                <button class="accordion-button <?= $i?'collapsed':'' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#collapseAct<?=$i?>">
                  <?= $month ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div id="collapseAct<?=$i?>" 
                   class="accordion-collapse collapse <?= $i?'':'show' ?>"
                   data-bs-parent="#accordionActive">
                <div class="accordion-body p-0">
                  <ul class="list-group">
                    <?php foreach($list as $hw): ?>
                    <li class="list-group-item d-flex justify-content-between
                        <?= $hw['expired']?'expired':'' ?>">
                      <div>
                        <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                        <small class="text-muted">
                          <?= nl2br(htmlspecialchars($hw['description'])) ?>
                        </small>
                        <?php if ($hw['file_path']): ?>
                        <div class="mt-1">
                          <a href="../<?= htmlspecialchars($hw['file_path']) ?>"
                             target="_blank"
                             class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download"></i> Attachment
                          </a>
                        </div>
                        <?php endif; ?>
                      </div>
                      <div class="text-end">
                        <?php if ($hw['submission']): ?>
                          <a href="../<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                             class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-file-earmark-arrow-down"></i> View
                          </a>
                        <?php elseif ($hw['locked']): ?>
                          <i class="bi bi-lock-fill fs-4 text-secondary"></i>
                        <?php else: ?>
                          <form action="/../actions/upload_homework.php"
                                method="POST"
                                enctype="multipart/form-data"
                                class="d-flex">
                            <input type="hidden" name="assignment_id"
                                   value="<?= $hw['assignment_id'] ?>">
                            <input type="file" name="submission"
                                   class="form-control form-control-sm me-2" required>
                            <button class="btn btn-sm btn-primary">Upload</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            </div>
            <?php $i++; endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Archived Tab -->
      <div class="tab-pane fade" id="archived" role="tabpanel">
        <?php if (empty($archivedGroups)): ?>
          <div class="alert alert-info">No archived assignments.</div>
        <?php else: ?>
          <div class="accordion" id="accordionArch">
            <?php $j=0; foreach($archivedGroups as $month=>$list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headingArch<?=$j?>">
                <button class="accordion-button <?= $j?'collapsed':'' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#collapseArch<?=$j?>">
                  <?= $month ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div id="collapseArch<?=$j?>" 
                   class="accordion-collapse collapse <?= $j?'':'show' ?>"
                   data-bs-parent="#accordionArch">
                <div class="accordion-body p-0">
                  <ul class="list-group">
                    <?php foreach($list as $hw): ?>
                    <li class="list-group-item d-flex justify-content-between
                        <?= $hw['expired']?'expired':'' ?>">
                      <div>
                        <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                        <small class="text-muted">
                          <?= nl2br(htmlspecialchars($hw['description'])) ?>
                        </small>
                        <?php if ($hw['file_path']): ?>
                        <div class="mt-1">
                          <a href="/<?= htmlspecialchars($hw['file_path']) ?>"
                             target="_blank"
                             class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-download"></i> Attachment
                          </a>
                        </div>
                        <?php endif; ?>
                      </div>
                      <div>
                        <?php if ($hw['submission']): ?>
                          <a href="/<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                             class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-file-earmark-arrow-down"></i> View
                          </a>
                        <?php else: ?>
                          <span class="badge bg-secondary">
                            <?= $hw['expired']? 'Expired':'—' ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            </div>
            <?php $j++; endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
