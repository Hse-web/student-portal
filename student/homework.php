<?php
// File: dashboard/student/homework.php

// (NO require_header or HTML wrappers here—index.php handles that.)

// Load data
$studentId = (int)($_SESSION['student_id'] ?? 0);

// 1) All assignments
$stmt = $conn->prepare("
  SELECT id AS assignment_id, date_assigned, title, description, file_path
    FROM homework_assigned
   WHERE student_id = ?
   ORDER BY date_assigned DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2) Submissions
$stmt = $conn->prepare("
  SELECT assignment_id, file_path
    FROM homework_submissions
   WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$subs = [];
foreach ($rows as $r) {
    $subs[(int)$r['assignment_id']] = $r;
}

// 3) Split active vs archived
$today    = new DateTime('today');
$active   = $archived = [];
foreach ($all as $hw) {
    $dt        = DateTime::createFromFormat('Y-m-d', $hw['date_assigned']);
    $diff      = $dt ? $dt->diff($today)->days : 0;
    $submitted = isset($subs[$hw['assignment_id']]);
    $expired   = (!$submitted && $diff > 90);

    if ($submitted || $expired) {
        $archived[] = array_merge($hw, ['submission' => $subs[$hw['assignment_id']] ?? null]);
    } else {
        $active[] = array_merge($hw, ['submission' => null]);
    }
}

// 4) Counts
$cntPending   = count($active);
$cntSubmitted = count(array_filter($all, function($h) use($subs){
    return isset($subs[$h['assignment_id']]);
}));
$cntExpired   = count(array_filter($all, function($h){
    $dt = DateTime::createFromFormat('Y-m-d', $h['date_assigned']);
    return $dt && $dt->diff(new DateTime('today'))->days > 7;
}));

// 5) Group helper
function groupByMonth(array $list): array {
    $out = [];
    foreach ($list as $hw) {
        // date_assigned is Y-m-d
        $m = (new DateTime($hw['date_assigned']))->format('F Y');
        $out[$m][] = $hw;
    }
    return $out;
}
$activeGroups   = groupByMonth($active);
$archivedGroups = groupByMonth($archived);
?>
<style>
  /* Inline override to restore Bootstrap’s collapse/show behavior */
  .accordion-collapse.collapse:not(.show) {
    display: none !important;
    visibility: hidden !important;
  }
  .accordion-collapse.collapse.show {
    display: block !important;
    visibility: visible !important;
  }
</style>
<div class="container-fluid px-4 py-6">

  <!-- Summary Cards -->
  <div class="row gx-3 gy-4 mb-5">
    <div class="col-12 col-md-4">
      <div class="card text-center p-3 shadow-sm">
        <i class="bi bi-hourglass-split fs-1 text-warning"></i>
        <h5 class="mt-2">Pending</h5>
        <div class="display-5"><?= $cntPending ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card text-center p-3 shadow-sm">
        <i class="bi bi-check-circle fs-1 text-success"></i>
        <h5 class="mt-2">Submitted</h5>
        <div class="display-5"><?= $cntSubmitted ?></div>
      </div>
    </div>
    <div class="col-12 col-md-4">
      <div class="card text-center p-3 shadow-sm">
        <i class="bi bi-calendar-x fs-1 text-secondary"></i>
        <h5 class="mt-2">Expired</h5>
        <div class="display-5"><?= $cntExpired ?></div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="overflow-x-auto mb-4">
    <ul class="nav nav-tabs" id="hwTabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button
          class="nav-link active"
          id="hw-active-tab"
          data-bs-toggle="tab"
          data-bs-target="#hw-active-pane"
          type="button" role="tab"
          aria-controls="hw-active-pane"
          aria-selected="true"
        >Active</button>
      </li>
      <li class="nav-item" role="presentation">
        <button
          class="nav-link"
          id="hw-archived-tab"
          data-bs-toggle="tab"
          data-bs-target="#hw-archived-pane"
          type="button" role="tab"
          aria-controls="hw-archived-pane"
          aria-selected="false"
        >Archived</button>
      </li>
    </ul>
  </div>

  <div class="tab-content" id="hwTabsContent">
    <!-- Active Pane -->
    <div class="tab-pane fade show active" id="hw-active-pane" role="tabpanel">
      <?php if (empty($activeGroups)): ?>
        <div class="alert alert-info">No active assignments.</div>
      <?php else: ?>
        <div class="accordion" id="accordionActive">
          <?php $i = 0; foreach ($activeGroups as $month => $list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headAct<?= $i ?>">
                <button
                  class="accordion-button <?= $i ? 'collapsed' : '' ?>"
                  type="button" data-bs-toggle="collapse"
                  data-bs-target="#collapseAct<?= $i ?>"
                  aria-expanded="<?= $i ? 'false' : 'true' ?>"
                  aria-controls="collapseAct<?= $i ?>"
                >
                  <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div
                id="collapseAct<?= $i ?>"
                class="accordion-collapse collapse <?= $i ? '' : 'show' ?>"
                aria-labelledby="headAct<?= $i ?>"
                data-bs-parent="#accordionActive"
              >
                <div class="accordion-body p-0">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($list as $hw): ?>
                      <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                        <div class="me-3 flex-grow-1">
                          <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                          <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'])) ?></small>
                          <?php if ($hw['file_path']): ?>
                            <div class="mt-2">
                              <a
                                href="../../<?= htmlspecialchars($hw['file_path']) ?>"
                                class="btn btn-sm btn-outline-secondary"
                              ><i class="bi bi-download"></i> Attachment</a>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="mt-2 mt-md-0">
                          <?php if ($hw['submission']): ?>
                            <a
                              href="../../<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                              class="btn btn-sm btn-outline-primary"
                            ><i class="bi bi-file-earmark-arrow-down" target="_blank"></i> View</a>
                          <?php else: ?>
                            <form action="../actions/upload_homework.php" method="POST"
                              enctype="multipart/form-data"
                              class="d-flex align-items-center" >
                              <input
                                type="hidden"
                                name="assignment_id"
                                value="<?= (int)$hw['assignment_id'] ?>">
                              <input
                                type="file"
                                name="submission"
                                class="form-control form-control-sm me-2" required >
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

    <!-- Archived Pane -->
    <div class="tab-pane fade" id="hw-archived-pane" role="tabpanel">
      <?php if (empty($archivedGroups)): ?>
        <div class="alert alert-info">No archived assignments.</div>
      <?php else: ?>
        <div class="accordion" id="accordionArch">
          <?php $j = 0; foreach ($archivedGroups as $month => $list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headArch<?= $j ?>">
                <button
                  class="accordion-button <?= $j ? 'collapsed' : '' ?>"
                  type="button" data-bs-toggle="collapse"
                  data-bs-target="#collapseArch<?= $j ?>"
                  aria-expanded="<?= $j ? 'false' : 'true' ?>"
                  aria-controls="collapseArch<?= $j ?>"
                >
                  <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div
                id="collapseArch<?= $j ?>"
                class="accordion-collapse collapse <?= $j ? '' : 'show' ?>"
                aria-labelledby="headArch<?= $j ?>"
                data-bs-parent="#accordionArch"
              >
                <div class="accordion-body p-0">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($list as $hw): ?>
                      <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                        <div class="me-3 flex-grow-1">
                          <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                          <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'])) ?></small>
                        </div>
                        <div class="mt-2 mt-md-0">
                          <?php if ($hw['submission']): ?>
                            <a
                              href="artovue/<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                              class="btn btn-sm btn-outline-primary"
                            ><i class="bi bi-file-earmark-arrow-down"></i> View</a>
                          <?php else: ?>
                            <span class="badge bg-secondary">
                              <?= $hw['expired'] ? 'Expired' : '—' ?>
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
