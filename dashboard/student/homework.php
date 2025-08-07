<?php
// File: dashboard/student/homework.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
  header('Location:/artovue/login.php');
  exit;
}

// 1) Pull assignments
$stmt = $conn->prepare("
  SELECT 
    ha.id            AS assignment_id,
    ha.date_assigned,
    ha.title,
    ha.description,
    ha.file_path
  FROM homework_assigned ha
  WHERE ha.student_id = ?
  ORDER BY ha.date_assigned DESC
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2) Pull submissions
$stmt = $conn->prepare("
  SELECT 
    assignment_id,
    file_path,
    shown_in_class,
    feedback,
    star_given,
    submitted_at
  FROM homework_submissions
  WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$subsRaw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$subs = [];
foreach ($subsRaw as $r) {
  $subs[$r['assignment_id']] = $r;
}

// 3) Bucket & apply 7-day expiry
$today    = new DateTime;
$upcoming = $active = $archived = [];

foreach ($assignments as $hw) {
  $assigned = new DateTime($hw['date_assigned'] ?? '');
  $daysOld  = $assigned->diff($today)->days;
  $meta     = $subs[$hw['assignment_id']] ?? null;

  // Future → upcoming
  if ($assigned > $today) {
    $upcoming[] = $hw;
    continue;
  }

  // Submitted → archived
  if (!empty($meta['file_path'])) {
    $hw['submission'] = $meta['file_path'];
    $hw['shown']      = (bool)$meta['shown_in_class'];
    $hw['feedback']   = $meta['feedback'] ?? '';
    $hw['stars']      = $meta['star_given'] ?? 0;
    $hw['expired']    = false;
    $archived[]       = $hw;
    continue;
  }

  // Expired → archived
  if ($daysOld > 7) {
    $hw['submission'] = null;
    $hw['shown']      = false;
    $hw['feedback']   = '';
    $hw['stars']      = 0;
    $hw['expired']    = true;
    $archived[]       = $hw;
    continue;
  }

  // Otherwise → active
  $hw['days_left']  = 7 - $daysOld;
  $hw['submission'] = null;
  $hw['shown']      = false;
  $hw['feedback']   = '';
  $hw['stars']      = 0;
  $active[]         = $hw;
}

// group by “Month Year”
function groupByMonth(array $list): array {
  $out = [];
  foreach ($list as $hw) {
    $m = (new DateTime($hw['date_assigned'] ?? ''))->format('F Y');
    $out[$m][] = $hw;
  }
  return $out;
}

$upG  = groupByMonth($upcoming);
$actG = groupByMonth($active);
$arcG = groupByMonth($archived);
$flash = get_flash();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Homework – Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    .btn-view    { padding:.25rem .5rem; font-size:.8rem; }
    .badge-days  { padding:.5em .75em; }
    /* fully hide collapsed accordions */
    .accordion-collapse { display:none!important; visibility:hidden!important; }
    .accordion-collapse.show { display:block!important; visibility:visible!important; }
  </style>
</head>
<body class="bg-light">
  <div class="container py-4">

    <?php if ($flash): ?>
      <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?>">
        <?= htmlspecialchars($flash['msg'] ?? '') ?>
      </div>
    <?php endif; ?>

    <ul class="nav nav-tabs mb-4" role="tablist">
      <li class="nav-item" role="presentation">
        <button
          class="nav-link active"
          id="tab-upcoming-btn"
          data-bs-toggle="tab"
          data-bs-target="#tab-upcoming"
          type="button"
          role="tab"
          aria-controls="tab-upcoming"
          aria-selected="true">
          Upcoming <span class="badge bg-warning"><?= count($upcoming) ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button
          class="nav-link"
          id="tab-active-btn"
          data-bs-toggle="tab"
          data-bs-target="#tab-active"
          type="button"
          role="tab"
          aria-controls="tab-active"
          aria-selected="false">
          Active <span class="badge bg-primary"><?= count($active) ?></span>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button
          class="nav-link"
          id="tab-archived-btn"
          data-bs-toggle="tab"
          data-bs-target="#tab-archived"
          type="button"
          role="tab"
          aria-controls="tab-archived"
          aria-selected="false">
          Archived <span class="badge bg-secondary"><?= count($archived) ?></span>
        </button>
      </li>
    </ul>

    <div class="tab-content">

      <!-- UPCOMING -->
      <div
        class="tab-pane fade show active"
        id="tab-upcoming"
        role="tabpanel"
        aria-labelledby="tab-upcoming-btn">
        <?php if (empty($upG)): ?>
          <div class="alert alert-info">No upcoming assignments.</div>
        <?php else: ?>
          <div class="accordion" id="upcomingAcc">
            <?php foreach ($upG as $month => $list): ?>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button
                    class="accordion-button collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#up-<?= md5($month) ?>"
                    aria-expanded="false">
                    <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                  </button>
                </h2>
                <div
                  id="up-<?= md5($month) ?>"
                  class="accordion-collapse collapse"
                  data-bs-parent="#upcomingAcc">
                  <div class="accordion-body p-0">
                    <ul class="list-group">
                      <?php foreach ($list as $hw): ?>
                        <li class="list-group-item d-flex justify-content-between">
                          <div>
                            <strong><?= htmlspecialchars($hw['title'] ?? '') ?></strong><br>
                            <small class="text-muted">
                              Assigned <?= htmlspecialchars($hw['date_assigned'] ?? '') ?>
                            </small>
                          </div>
                          <span class="badge bg-warning text-dark">
                            <i class="bi bi-lock-fill"></i> Locked
                          </span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ACTIVE -->
      <div
        class="tab-pane fade"
        id="tab-active"
        role="tabpanel"
        aria-labelledby="tab-active-btn">
        <?php if (empty($actG)): ?>
          <div class="alert alert-info">No active assignments.</div>
        <?php else: ?>
          <div class="accordion" id="activeAcc">
            <?php foreach ($actG as $month => $list): ?>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button
                    class="accordion-button collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ac-<?= md5($month) ?>"
                    aria-expanded="false">
                    <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                  </button>
                </h2>
                <div
                  id="ac-<?= md5($month) ?>"
                  class="accordion-collapse collapse"
                  data-bs-parent="#activeAcc">
                  <div class="accordion-body p-0">
                    <ul class="list-group">
                      <?php foreach ($list as $hw): ?>
                        <li class="list-group-item">
                          <div class="d-flex justify-content-between align-items-center">
                            <div>
                              <strong><?= htmlspecialchars($hw['title'] ?? '') ?></strong><br>
                              <small class="text-muted">
                                <?= nl2br(htmlspecialchars($hw['description'] ?? '')) ?>
                              </small>
                            </div>
                            <span class="badge bg-info badge-days">
                              <?= (int)$hw['days_left'] ?>d left
                            </span>
                          </div>
                          <div class="mt-2">
                            <?php if (!empty($hw['file_path'])): ?>
                              <a
                                href="/artovue/<?= htmlspecialchars($hw['file_path']) ?>"
                                class="btn btn-sm btn-outline-secondary me-1">
                                Download
                              </a>
                            <?php endif; ?>

                            <?php if (!empty($hw['submission'])): ?>
                              <a
                                href="/artovue/<?= htmlspecialchars($hw['submission']) ?>"
                                target="_blank"
                                class="btn btn-sm btn-primary btn-view">
                                View Submission
                              </a>
                            <?php else: ?>
                              <form
                                action="/artovue/actions/upload_homework.php"
                                method="post"
                                enctype="multipart/form-data"
                                class="d-inline">
                                <input type="hidden"
                                       name="assignment_id"
                                       value="<?= (int)$hw['assignment_id'] ?>">
                                <input type="file"
                                       name="submission"
                                       required
                                       class="form-control-sm d-inline-block">
                                <button class="btn btn-sm btn-success btn-view">
                                  Upload
                                </button>
                              </form>
                            <?php endif; ?>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- ARCHIVED -->
      <div
        class="tab-pane fade"
        id="tab-archived"
        role="tabpanel"
        aria-labelledby="tab-archived-btn">
        <?php if (empty($arcG)): ?>
          <div class="alert alert-info">No archived assignments.</div>
        <?php else: ?>
          <div class="accordion" id="archAcc">
            <?php foreach ($arcG as $month => $list): ?>
              <div class="accordion-item">
                <h2 class="accordion-header">
                  <button
                    class="accordion-button collapsed"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#ar-<?= md5($month) ?>"
                    aria-expanded="false">
                    <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
                  </button>
                </h2>
                <div
                  id="ar-<?= md5($month) ?>"
                  class="accordion-collapse collapse"
                  data-bs-parent="#archAcc">
                  <div class="accordion-body p-0">
                    <ul class="list-group">
                      <?php foreach ($list as $hw): ?>
                        <li class="list-group-item">
                          <div class="d-flex justify-content-between align-items-center">
                            <div>
                              <strong><?= htmlspecialchars($hw['title'] ?? '') ?></strong><br>
                              <?php if (!empty($hw['description'])): ?>
                                <small class="text-muted">
                                  <?= nl2br(htmlspecialchars($hw['description'])) ?>
                                </small><br>
                              <?php endif; ?>
                            </div>
                            <div class="text-end">
                              <?php if (!empty($hw['submission'])): ?>
                                <a
                                  href="/artovue/<?= htmlspecialchars($hw['submission']) ?>"
                                  target="_blank"
                                  class="btn btn-sm btn-primary btn-view mb-1">
                                  View Submission
                                </a><br>
                                <small>⭐ <?= (int)$hw['stars'] ?> / 3</small><br>
                                <small><?= nl2br(htmlspecialchars($hw['feedback'])) ?></small>
                              <?php elseif (!empty($hw['shown'])): ?>
                                <span class="badge bg-info">Shown in Class</span>
                              <?php elseif (!empty($hw['expired'])): ?>
                                <span class="badge bg-secondary">Expired</span>
                              <?php else: ?>
                                <span class="badge bg-danger">Not Submitted</span>
                              <?php endif; ?>
                            </div>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
