<?php
// File: dashboard/student/homework.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    header('Location: /artovue/login.php');
    exit;
}

// 1) All assignments
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

// 2) Any submissions
$stmt = $conn->prepare("
  SELECT assignment_id, file_path
    FROM homework_submissions
   WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$subs = [];
foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $subs[$r['assignment_id']] = $r['file_path'];
}
$stmt->close();

// 3) Bucket them
$todayStr   = (new DateTime())->format('Y-m-d');
$upcoming   = $active = $archived = [];

foreach ($assignments as $hw) {
    $assignedDate = $hw['date_assigned'];          // 'YYYY-MM-DD'
    $submitted    = isset($subs[$hw['assignment_id']]);

    // daysOld only matters for >90-day archive, but we handle today vs future strictly by string
    $daysOld = (new DateTime($assignedDate))
                   ->diff(new DateTime($todayStr))
                   ->days;

    if ($assignedDate > $todayStr) {
        // strictly future
        $upcoming[] = $hw;
    }
    elseif ($submitted) {
        // already submitted
        $archived[] = array_merge($hw, [
          'submission' => $subs[$hw['assignment_id']], 
          'expired'    => false
        ]);
    }
    elseif ($daysOld > 90) {
        // too old
        $archived[] = array_merge($hw, [
          'submission' => null,
          'expired'    => true
        ]);
    }
    else {
        // today or within 90 days
        $active[] = array_merge($hw, [
          'submission' => $subs[$hw['assignment_id']] ?? null
        ]);
    }
}

// group by month helper
function groupByMonth(array $list): array {
    $out = [];
    foreach ($list as $hw) {
        $m = (new DateTime($hw['date_assigned']))->format('F Y');
        $out[$m][] = $hw;
    }
    return $out;
}

$upcomingGroups = groupByMonth($upcoming);
$activeGroups   = groupByMonth($active);
$archivedGroups = groupByMonth($archived);
$flash          = get_flash();
?>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>My Homework – Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body class="bg-light">
  <div class="container py-4">

    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>">
        <?= htmlspecialchars($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" id="hwTabs">
      <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-upcoming">
          Upcoming <span class="badge bg-warning"><?= count($upcoming) ?></span>
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-active">
          Active <span class="badge bg-primary"><?= count($active) ?></span>
        </button>
      </li>
      <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-archived">
          Archived <span class="badge bg-secondary"><?= count($archived) ?></span>
        </button>
      </li>
    </ul>

    <div class="tab-content">

      <!-- Upcoming -->
      <div class="tab-pane fade show active" id="tab-upcoming">
        <?php if (empty($upcomingGroups)): ?>
          <div class="alert alert-info">No upcoming assignments.</div>
        <?php else: ?>
          <?php foreach ($upcomingGroups as $month => $list): ?>
            <h5 class="mt-4"><?= htmlspecialchars($month) ?></h5>
            <ul class="list-group mb-4">
              <?php foreach ($list as $hw): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                    <small class="text-muted">
                      Assigned <?= htmlspecialchars($hw['date_assigned']) ?>
                    </small>
                  </div>
                  <span class="badge bg-warning text-dark">
                    <i class="bi bi-lock-fill"></i> Locked
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Active -->
      <div class="tab-pane fade" id="tab-active">
        <?php if (empty($activeGroups)): ?>
          <div class="alert alert-info">No active assignments.</div>
        <?php else: ?>
          <?php foreach ($activeGroups as $month => $list): ?>
            <h5 class="mt-4"><?= htmlspecialchars($month) ?></h5>
            <ul class="list-group mb-4">
              <?php foreach ($list as $hw): ?>
                <li class="list-group-item">
                  <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                  <small class="text-muted">
                    <?= nl2br(htmlspecialchars($hw['description'])) ?>
                  </small>

                  <?php if ($hw['file_path']): ?>
                    <a href="/artovue/<?= htmlspecialchars($hw['file_path']) ?>"
                       class="btn btn-sm btn-outline-secondary ms-2">
                      Download
                    </a>
                  <?php endif; ?>

                  <?php if ($hw['submission']): ?>
                    <a href="/artovue/<?= htmlspecialchars($hw['submission']) ?>"
                       class="btn btn-sm btn-outline-primary ms-2">
                      View Submission
                    </a>
                  <?php else: ?>
                    <form action="/artovue/actions/upload_homework.php"
                          method="post" enctype="multipart/form-data"
                          class="d-inline ms-2">
                      <input type="hidden" name="assignment_id"
                             value="<?= $hw['assignment_id'] ?>">
                      <input type="file" name="submission" required
                             class="form-control-sm">
                      <button class="btn btn-sm btn-success">Upload</button>
                    </form>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Archived -->
      <div class="tab-pane fade" id="tab-archived">
        <?php if (empty($archivedGroups)): ?>
          <div class="alert alert-info">No archived assignments.</div>
        <?php else: ?>
          <?php foreach ($archivedGroups as $month => $list): ?>
            <h5 class="mt-4"><?= htmlspecialchars($month) ?></h5>
            <ul class="list-group mb-4">
              <?php foreach ($list as $hw): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <div>
                    <strong><?= htmlspecialchars($hw['title']) ?></strong><br>
                    <small class="text-muted">
                      <?= nl2br(htmlspecialchars($hw['description'])) ?>
                    </small>
                  </div>
                  <?php if ($hw['submission']): ?>
                    <a href="/artovue/<?= htmlspecialchars($hw['submission']) ?>"
                       class="btn btn-sm btn-outline-primary">
                      View Submission
                    </a>
                  <?php else: ?>
                    <span class="badge bg-secondary">Not Submitted</span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <script 
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
