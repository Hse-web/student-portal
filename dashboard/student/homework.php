<?php
// File: dashboard/student/homework.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}

// 1) Load all assignments
$stmt = $conn->prepare("
  SELECT id AS assignment_id, date_assigned, title, description, file_path
    FROM homework_assigned
   WHERE student_id = ?
   ORDER BY date_assigned DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$allAssignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2) Load submissions
$stmt = $conn->prepare("
  SELECT assignment_id, file_path, submitted_at
    FROM homework_submissions
   WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$submissionRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$subs = [];
foreach ($submissionRows as $r) {
    $subs[(int)$r['assignment_id']] = $r;
}

// 3) Split into active vs archived
$today = new DateTime('today');
$activeList   = [];
$archivedList = [];

foreach ($allAssignments as $hw) {
    $dt = DateTime::createFromFormat('Y-m-d', $hw['date_assigned']);
    $locked = $dt && $dt > $today;
    $diff   = $dt ? $dt->diff($today)->days : 0;
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

// 4) Counts for the cards
$counts = ['pending'=>0,'submitted'=>0,'expired'=>0];
foreach ($allAssignments as $hw) {
    $aid = (int)$hw['assignment_id'];
    if (isset($subs[$aid])) {
        $counts['submitted']++;
    } else {
        $dt = DateTime::createFromFormat('Y-m-d', $hw['date_assigned']);
        if ($dt && $dt <= $today && $dt->diff($today)->days > 90) {
            $counts['expired']++;
        } else {
            $counts['pending']++;
        }
    }
}

// 5) Group by month
function groupByMonth(array $list): array {
    $out = [];
    foreach ($list as $hw) {
        $m = (new DateTime($hw['date_assigned']))->format('F Y');
        $out[$m][] = $hw;
    }
    return $out;
}
$activeGroups   = groupByMonth($activeList);
$archivedGroups = groupByMonth($archivedList);

?>
<div class="container-fluid py-4">

  <!-- Summary Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card text-center p-3">
        <i class="bi bi-hourglass-split fs-1 text-warning"></i>
        <h5 class="mt-2">Pending</h5>
        <div class="display-5"><?= $counts['pending'] ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card text-center p-3">
        <i class="bi bi-check-circle fs-1 text-success"></i>
        <h5 class="mt-2">Submitted</h5>
        <div class="display-5"><?= $counts['submitted'] ?></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card text-center p-3">
        <i class="bi bi-calendar-x fs-1 text-secondary"></i>
        <h5 class="mt-2">Expired</h5>
        <div class="display-5"><?= $counts['expired'] ?></div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3" id="hwTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button
        class="nav-link active"
        id="hw-active-tab" data-bs-toggle="tab" data-bs-target="#hw-active-pane" type="button" role="tab" aria-controls="hw-active-pane" aria-selected="true">Active</button>
    </li>
    <li class="nav-item" role="presentation">
      <button
        class="nav-link"
        id="hw-archived-tab"
        data-bs-toggle="tab"
        data-bs-target="#hw-archived-pane"
        type="button"
        role="tab"
        aria-controls="hw-archived-pane"
        aria-selected="false"
      >Archived</button>
    </li>
  </ul>

  <div class="tab-content" id="hwTabsContent">
    <!-- Active Pane -->
    <div
      class="tab-pane fade show active"
      id="hw-active-pane"
      role="tabpanel"
      aria-labelledby="hw-active-tab"
    >
      <?php if (empty($activeGroups)): ?>
        <div class="alert alert-info">No active assignments.</div>
      <?php else: ?>
        <div class="accordion" id="accordionActive">
          <?php $i=0; foreach($activeGroups as $month=>$list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headAct<?= $i ?>">
                <button
                  class="accordion-button <?= $i? 'collapsed':'' ?>"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseAct<?= $i ?>"
                  aria-expanded="<?= $i? 'false':'true' ?>"
                  aria-controls="collapseAct<?= $i ?>"
                >
                  <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div
                id="collapseAct<?= $i ?>"
                class="accordion-collapse collapse <?= $i? '':'show' ?>"
                aria-labelledby="headAct<?= $i ?>"
                data-bs-parent="#accordionActive"
              >
                <!-- padded body -->
                <div class="accordion-body">
                  <div class="list-group list-group-flush">
                    <?php foreach($list as $hw): ?>
                      <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                          <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                          <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'])) ?></small>
                          <?php if($hw['file_path']): ?>
                            <div class="mt-1">
                              <a href="/student-portal/<?= htmlspecialchars($hw['file_path']) ?>"
                                 target="_blank"
                                 class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-download"></i> Attachment
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div>
                          <?php if($hw['submission']): ?>
                            <a href="/student-portal/<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                               class="btn btn-sm btn-outline-primary">
                              <i class="bi bi-file-earmark-arrow-down"></i> View
                            </a>
                          <?php elseif($hw['locked']): ?>
                            <i class="bi bi-lock-fill fs-4 text-secondary"></i>
                          <?php else: ?>
                            <form action="/student-portal/actions/upload_homework.php"
                                  method="POST"
                                  enctype="multipart/form-data"
                                  class="d-flex align-items-center">
                              <input type="hidden" name="assignment_id"
                                     value="<?= (int)$hw['assignment_id'] ?>">
                              <input type="file" name="submission"
                                     class="form-control form-control-sm me-2" required>
                              <button class="btn btn-sm btn-primary">Upload</button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php $i++; endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Archived Pane -->
    <div
      class="tab-pane fade"
      id="hw-archived-pane"
      role="tabpanel"
      aria-labelledby="hw-archived-tab"
    >
      <?php if (empty($archivedGroups)): ?>
        <div class="alert alert-info">No archived assignments.</div>
      <?php else: ?>
        <div class="accordion" id="accordionArch">
          <?php $j=0; foreach($archivedGroups as $month=>$list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headArch<?= $j ?>">
                <button
                  class="accordion-button <?= $j? 'collapsed':'' ?>"
                  type="button"
                  data-bs-toggle="collapse"
                  data-bs-target="#collapseArch<?= $j ?>"
                  aria-expanded="<?= $j? 'false':'true' ?>"
                  aria-controls="collapseArch<?= $j ?>"
                >
                  <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div
                id="collapseArch<?= $j ?>"
                class="accordion-collapse collapse <?= $j? '':'show' ?>"
                aria-labelledby="headArch<?= $j ?>"
                data-bs-parent="#accordionArch"
              >
                <div class="accordion-body">
                  <div class="list-group list-group-flush">
                    <?php foreach($list as $hw): ?>
                      <div class="list-group-item d-flex justify-content-between">
                        <div>
                          <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                          <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'])) ?></small>
                          <?php if($hw['file_path']): ?>
                            <div class="mt-1">
                              <a href="/student-portal/<?= htmlspecialchars($hw['file_path']) ?>"
                                 target="_blank"
                                 class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-download"></i> Attachment
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div>
                          <?php if($hw['submission']): ?>
                            <a href="/student-portal/<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                               class="btn btn-sm btn-outline-primary">
                              <i class="bi bi-file-earmark-arrow-down"></i> View
                            </a>
                          <?php else: ?>
                            <span class="badge bg-secondary">
                              <?= $hw['expired']? 'Expired':'—' ?>
                            </span>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php $j++; endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Include Bootstrap Bundle once, at the very end -->
<script
  src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"
  integrity="sha384-..."
  crossorigin="anonymous"
></script>
