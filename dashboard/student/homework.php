<?php
// File: dashboard/student/homework.php

// ─── 1) Bootstrap + “student” Auth Guard ─────────────────────────────────
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// ─── 2) Identify logged-in student ────────────────────────────────────────
$studentId = (int) ($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: ../../login.php');
    exit;
}

// ─── 3) Fetch all assignments for this student ────────────────────────────
// We'll retrieve every row from homework_assigned for the current student:
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
$stmt->bind_param('i', $studentId);
$stmt->execute();
$allAssignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── 4) Fetch all existing submissions from this student ───────────────────
$stmt = $conn->prepare("
  SELECT assignment_id, file_path, submitted_at
    FROM homework_submissions
   WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$submissionRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Turn that into an associative array keyed by assignment_id
$subs = [];
foreach ($submissionRows as $row) {
    $subs[(int)$row['assignment_id']] = $row;
}

// ─── 5) Classify each assignment as “locked”, “expired”, or “submitted” ───
$today = new DateTime('today');
$activeList   = [];
$archivedList = [];

foreach ($allAssignments as $hw) {
    // Parse the date_assigned
    $dt = DateTime::createFromFormat('Y-m-d', $hw['date_assigned']);
    if (! $dt) {
        // If date is malformed for some reason, treat as “active but no exact date”
        $locked    = false;
        $expired   = false;
    } else {
        $locked  = ($dt > $today);          // future assignment
        $diff    = $dt->diff($today)->days;  // days difference
        $expired = (! $locked && $diff > 90);
    }

    $submitted = isset($subs[$hw['assignment_id']]);

    // Attach metadata back into the same row
    $hw['locked']     = $locked;
    $hw['expired']    = $expired;
    $hw['submission'] = $submitted ? $subs[$hw['assignment_id']] : null;

    if ($submitted || $expired) {
        $archivedList[] = $hw;
    } else {
        $activeList[] = $hw;
    }
}

// ─── 6) Compute the summary counts ────────────────────────────────────────
$counts = [
  'pending'   => 0,
  'submitted' => 0,
  'expired'   => 0,
];
foreach ($allAssignments as $hw) {
    $aid = (int)$hw['assignment_id'];
    if (isset($subs[$aid])) {
        $counts['submitted']++;
    } else {
        $dt = DateTime::createFromFormat('Y-m-d', $hw['date_assigned']);
        if ($dt && $dt <= $today && $dt->diff($today)->days > 90) {
            $counts['expired']++;
        } else {
            // Upcoming (locked) or still within 90 days are considered “Pending”
            $counts['pending']++;
        }
    }
}

// ─── 7) Group by “Month Year” ─────────────────────────────────────────────
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

// ─── 8) Render page header + sidebar + topbar ────────────────────────────
$page = 'homework';
render_student_header('My Homework');
?>

<div class="container-fluid py-4">

  <!-- ─── Summary Cards ──────────────────────────────────────────────── -->
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

  <!-- ─── Tabs “Active” / “Archived” ────────────────────────────────── -->
  <ul class="nav nav-tabs mb-3" id="hwTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="active-tab" data-bs-toggle="tab"
              data-bs-target="#active" type="button" role="tab"
              aria-controls="active" aria-selected="true">
        Active
      </button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="archived-tab" data-bs-toggle="tab"
              data-bs-target="#archived" type="button" role="tab"
              aria-controls="archived" aria-selected="false">
        Archived
      </button>
    </li>
  </ul>

  <div class="tab-content" id="hwTabsContent">

    <!-- ─── Active Tab Pane ─────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="active" role="tabpanel" aria-labelledby="active-tab">
      <?php if (empty($activeGroups)): ?>
        <div class="alert alert-info">No active assignments.</div>
      <?php else: ?>
        <div class="accordion" id="accordionActive">
          <?php $i = 0; ?>
          <?php foreach ($activeGroups as $month => $list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headingAct<?= $i ?>">
                <button class="accordion-button <?= ($i === 0 ? '' : 'collapsed') ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#collapseAct<?= $i ?>"
                        aria-expanded="<?= ($i === 0 ? 'true' : 'false') ?>"
                        aria-controls="collapseAct<?= $i ?>">
                  <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div id="collapseAct<?= $i ?>"
                   class="accordion-collapse collapse <?= ($i === 0 ? 'show' : '') ?>"
                   aria-labelledby="headingAct<?= $i ?>"
                   data-bs-parent="#accordionActive">
                <div class="accordion-body p-0">
                  <ul class="list-group">
                    <?php foreach ($list as $hw): ?>
                      <li class="list-group-item d-flex justify-content-between 
                          <?= $hw['expired'] ? 'expired' : '' ?>">
                        <div>
                          <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                          <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'])) ?></small>
                          <?php if ($hw['file_path']): ?>
                            <div class="mt-1">
                              <a href="/student-portal/<?= htmlspecialchars($hw['file_path']) ?>"
                                 target="_blank"
                                 class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-download"></i> Attachment
                              </a>
                            </div>
                          <?php endif; ?>
                        </div>
                        <div class="text-end">
                          <?php if ($hw['submission']): ?>
                            <a href="/student-portal/<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                               class="btn btn-sm btn-outline-primary">
                              <i class="bi bi-file-earmark-arrow-down"></i> View
                            </a>
                          <?php elseif ($hw['locked']): ?>
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
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </div>
            </div>
            <?php $i++; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ─── Archived Tab Pane ───────────────────────────────────────────── -->
    <div class="tab-pane fade" id="archived" role="tabpanel" aria-labelledby="archived-tab">
      <?php if (empty($archivedGroups)): ?>
        <div class="alert alert-info">No archived assignments.</div>
      <?php else: ?>
        <div class="accordion" id="accordionArch">
          <?php $j = 0; ?>
          <?php foreach ($archivedGroups as $month => $list): ?>
            <div class="accordion-item">
              <h2 class="accordion-header" id="headingArch<?= $j ?>">
                <button class="accordion-button <?= ($j === 0 ? '' : 'collapsed') ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#collapseArch<?= $j ?>"
                        aria-expanded="<?= ($j === 0 ? 'true' : 'false') ?>"
                        aria-controls="collapseArch<?= $j ?>">
                  <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                </button>
              </h2>
              <div id="collapseArch<?= $j ?>"
                   class="accordion-collapse collapse <?= ($j === 0 ? 'show' : '') ?>"
                   aria-labelledby="headingArch<?= $j ?>"
                   data-bs-parent="#accordionArch">
                <div class="accordion-body p-0">
                  <ul class="list-group">
                    <?php foreach ($list as $hw): ?>
                      <li class="list-group-item d-flex justify-content-between
                          <?= $hw['expired'] ? 'expired' : '' ?>">
                        <div>
                          <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                          <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'])) ?></small>
                          <?php if ($hw['file_path']): ?>
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
                          <?php if ($hw['submission']): ?>
                            <a href="/student-portal/<?= htmlspecialchars($hw['submission']['file_path']) ?>"
                               class="btn btn-sm btn-outline-primary">
                              <i class="bi bi-file-earmark-arrow-down"></i> View
                            </a>
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
            <?php $j++; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ─── 9) Include Bootstrap JS so tabs & accordion work ─────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<?php
// ─── 10) Render the shared student footer & closing tags ─────────────────
render_student_footer();
?>
