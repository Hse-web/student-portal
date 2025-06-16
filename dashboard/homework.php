<?php
require_once __DIR__ . '/../includes/functions.php';
$flash = get_flash();
?>

<?php if ($flash): ?>
  <div class="container-fluid px-4 py-3">
    <div class="alert alert-<?= $flash['type'] === 'danger' ? 'danger' : 'success' ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  </div>
<?php endif; ?>

<div class="container-fluid px-4 py-6">
<?php
$studentId = (int)($_SESSION['student_id'] ?? 0);

// 1) Fetch assigned homework
$stmt = $conn->prepare("
  SELECT
    ha.id AS assignment_id,
    ha.date_assigned,
    ha.title,
    ha.description,
    hs.file_path AS submission,
    hs.feedback
  FROM homework_assigned ha
  LEFT JOIN homework_submissions hs
    ON ha.id = hs.assignment_id AND hs.student_id = ?
  WHERE ha.student_id = ?
  ORDER BY ha.date_assigned DESC
");
$stmt->bind_param('ii', $studentId, $studentId);
$stmt->execute();
$result = $stmt->get_result();
$all = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 2) Split active vs archived
$today = new DateTime('today');
$active = $archived = [];

foreach ($all as $hw) {
  $dt = DateTime::createFromFormat('Y-m-d', $hw['date_assigned']);
  $daysAgo = $dt ? $dt->diff($today)->days : 0;
  $submitted = !empty($hw['submission']);
  $expired = (!$submitted && $daysAgo > 90);
  $hw['expired'] = $expired;

  if ($submitted || $expired) {
    $archived[] = $hw;
  } else {
    $active[] = $hw;
  }
}

// 3) Group by month
function groupByMonth(array $list): array {
  $out = [];
  foreach ($list as $h) {
    $m = (new DateTime($h['date_assigned']))->format('F Y');
    $out[$m][] = $h;
  }
  return $out;
}

$activeGroups = groupByMonth($active);
$archivedGroups = groupByMonth($archived);
?>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#6b21a8">
<link rel="icon" href="/assets/icons/icon-192.png">
<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/service-worker.js')
      .then(() => console.log('✅ SW registered'))
      .catch(err => console.error('⚠️ SW registration failed:', err));
  }
</script>
<style>
.accordion-collapse.collapse:not(.show) {
  display: none !important;
  visibility: hidden !important;
}
.accordion-collapse.collapse.show {
  display: block !important;
  visibility: visible !important;
}
</style>

<!-- Summary -->
<div class="row gx-3 gy-4 mb-5">
  <div class="col-md-4 col-12">
    <div class="card text-center p-3 shadow-sm">
      <i class="bi bi-hourglass-split fs-1 text-warning"></i>
      <h5 class="mt-2">Pending</h5>
      <div class="display-5"><?= count($active) ?></div>
    </div>
  </div>
  <div class="col-md-4 col-12">
    <div class="card text-center p-3 shadow-sm">
      <i class="bi bi-check-circle fs-1 text-success"></i>
      <h5 class="mt-2">Submitted</h5>
      <div class="display-5">
        <?= count(array_filter($all, fn($h) => !empty($h['submission']))) ?>
      </div>
    </div>
  </div>
  <div class="col-md-4 col-12">
    <div class="card text-center p-3 shadow-sm">
      <i class="bi bi-calendar-x fs-1 text-secondary"></i>
      <h5 class="mt-2">Not Submitted</h5>
      <div class="display-5">
        <?= count(array_filter($all, fn($h) => empty($h['submission']) && ((new DateTime($h['date_assigned']))->diff($today)->days > 90))) ?>
      </div>
    </div>
  </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
  <li class="nav-item">
    <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#hw-active-pane" type="button" role="tab">
      Active
    </button>
  </li>
  <li class="nav-item">
    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#hw-archived-pane" type="button" role="tab">
      Archived
    </button>
  </li>
</ul>

<div class="tab-content" id="hwTabsContent">

  <!-- Active Tab -->
  <div class="tab-pane fade show active" id="hw-active-pane" role="tabpanel">
    <?php if (empty($activeGroups)): ?>
      <div class="alert alert-info">No active assignments.</div>
    <?php else: ?>
      <div class="accordion" id="accordionActive">
        <?php $i = 0; foreach ($activeGroups as $month => $list): ?>
          <div class="accordion-item">
            <h2 class="accordion-header" id="headAct<?= $i ?>">
              <button class="accordion-button <?= $i ? 'collapsed' : '' ?>" type="button"
                      data-bs-toggle="collapse" data-bs-target="#collapseAct<?= $i ?>"
                      aria-expanded="<?= $i ? 'false' : 'true' ?>">
                <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
              </button>
            </h2>
            <div id="collapseAct<?= $i ?>" class="accordion-collapse collapse <?= $i ? '' : 'show' ?>"
                 data-bs-parent="#accordionActive">
              <div class="accordion-body p-0">
                <ul class="list-group list-group-flush">
                  <?php foreach ($list as $hw): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap">
                      <div class="me-3 flex-grow-1">
                        <strong><?= htmlspecialchars($hw['title'] ?? '') ?></strong><br>
                        <small class="text-muted"><?= nl2br(htmlspecialchars($hw['description'] ?? '')) ?></small>
                      </div>
                      <div class="mt-2 mt-md-0 text-end">
                        <?php if (!empty($hw['submission'])): ?>
                          <a href="/artovue/<?= htmlspecialchars($hw['submission']) ?>"
                             class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="bi bi-file-earmark-arrow-down"></i> View
                          </a>
                          <?php if (!empty($hw['feedback'])): ?>
                            <div class="mt-2 small text-success border-start ps-2 border-3 border-success">
                              <strong>Teacher Feedback:</strong><br>
                              <?= nl2br(htmlspecialchars($hw['feedback'] ?? '')) ?>
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <form action="/artovue/actions/upload_homework.php"
                                method="POST" enctype="multipart/form-data"
                                class="d-flex align-items-center flex-wrap gap-2">
                            <input type="hidden" name="assignment_id" value="<?= (int)$hw['assignment_id'] ?>">
                            <input type="file" name="submission" class="form-control form-control-sm" required>
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
  <div class="tab-pane fade" id="hw-archived-pane" role="tabpanel">
    <?php if (empty($archivedGroups)): ?>
      <div class="alert alert-info">No archived assignments.</div>
    <?php else: ?>
      <div class="accordion" id="accordionArch">
        <?php $j = 0; foreach ($archivedGroups as $month => $list): ?>
          <div class="accordion-item">
            <h2 class="accordion-header" id="headArch<?= $j ?>">
              <button class="accordion-button <?= $j ? 'collapsed' : '' ?>" type="button"
                      data-bs-toggle="collapse" data-bs-target="#collapseArch<?= $j ?>"
                      aria-expanded="<?= $j ? 'false' : 'true' ?>">
                <?= htmlspecialchars($month) ?> (<?= count($list) ?>)
              </button>
            </h2>
            <div id="collapseArch<?= $j ?>" class="accordion-collapse collapse <?= $j ? '' : 'show' ?>"
                 data-bs-parent="#accordionArch">
              <div class="accordion-body p-0">
                <ul class="list-group list-group-flush">
                  <?php foreach ($list as $hw): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start flex-wrap">
                      <div class="me-3 flex-grow-1">
                        <strong><?= htmlspecialchars($hw['title']) ?></strong>
                      </div>
                      <div class="mt-2 mt-md-0">
                        <?php if (!empty($hw['submission'])): ?>
                          <a href="/artovue/<?= htmlspecialchars($hw['submission']) ?>"
                             class="btn btn-sm btn-outline-primary" target="_blank">
                            <i class="bi bi-file-earmark-arrow-down"></i> View
                          </a>
                          <?php if (!empty($hw['feedback'])): ?>
                            <div class="mt-2 small text-success border-start ps-2 border-3 border-success">
                              <strong>Teacher Feedback:</strong><br>
                              <?= nl2br(htmlspecialchars($hw['feedback'])) ?>
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="badge bg-secondary">Expired</span>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
