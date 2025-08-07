<?php
// File: dashboard/admin/assign_homework.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php'; // create_notification(), log_audit(), set_flash(), get_flash(), verify_csrf_token()
require_once __DIR__ . '/../../config/db.php';

date_default_timezone_set('Asia/Kolkata');

// CSRF token gen
$csrf = generate_csrf_token();

// Rate limiting — max 5 assignments every 10 mins per session
if (!isset($_SESSION['assign_history'])) {
    $_SESSION['assign_history'] = [];
}
$_SESSION['assign_history'] = array_filter($_SESSION['assign_history'], fn($ts) => $ts > time() - 600);

$errors = [];
$success = '';
$flash = get_flash();

// Load Art Groups and Centres for form & filters
$groups = $conn->query("
    SELECT ag.id, ag.label, ag.sort_order,
           COUNT(DISTINCT s.id) AS student_count
    FROM art_groups ag
    LEFT JOIN student_promotions sp ON ag.id = sp.art_group_id AND sp.is_applied = 1
    LEFT JOIN students s ON s.id = sp.student_id
    GROUP BY ag.id, ag.label, ag.sort_order
    ORDER BY ag.sort_order
")->fetch_all(MYSQLI_ASSOC);

$centres = $conn->query("SELECT id, name FROM centres ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// -------------------- HANDLE BATCH DELETE --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expired—reload and try again.';
    } else {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id > 0) {
            $stmt = $conn->prepare("
                SELECT ha.*, ag.label AS group_label
                FROM homework_assigned ha
                JOIN art_groups ag ON ha.art_group_id = ag.id
                WHERE ha.id = ?
            ");
            $stmt->bind_param('i', $batch_id);
            $stmt->execute();
            $batchInfo = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($batchInfo) {
                $stmt = $conn->prepare("
                    DELETE FROM homework_assigned
                    WHERE art_group_id = ? AND date_assigned = ? AND title = ?
                ");
                $stmt->bind_param('iss', $batchInfo['art_group_id'], $batchInfo['date_assigned'], $batchInfo['title']);
                $stmt->execute();
                $deletedCount = $stmt->affected_rows;
                $stmt->close();

                if (function_exists('log_audit')) {
                    log_audit($conn, $_SESSION['user_id'], 'DELETE', 'homework_batch', $batch_id, $batchInfo);
                }

                $success = "Deleted batch: {$batchInfo['title']} for {$batchInfo['group_label']} ($deletedCount assignments)";
            }
        }
    }
}

// -------------------- HANDLE NEW ASSIGNMENT --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Session expired—try again.';
    }
    if (count($_SESSION['assign_history']) >= 5) {
        $errors[] = 'Too many assignments in last 10 minutes.';
    }

    $group_id = (int)($_POST['group_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $date_assigned = trim($_POST['date_assigned'] ?? '');
    $centre_filter = (int)($_POST['centre_filter'] ?? 0);

    if ($group_id < 1) $errors[] = 'Please select an art group.';
    if ($title === '') $errors[] = 'Title cannot be blank.';
    if (strlen($title) > 255) $errors[] = 'Title is too long.';
    if ($description === '') $errors[] = 'Description cannot be blank.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_assigned)) {
        $errors[] = 'Please enter a valid date.';
    }

    // Handle file upload
    $uploadPath = '';
    if (!empty($_FILES['attachment']['tmp_name'])) {
        $f = $_FILES['attachment'];
        if ($f['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowedTypes = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            if (in_array($ext, $allowedTypes)) {
                if ($f['size'] <= 10 * 1024 * 1024) {
                    $uploadDir = __DIR__ . '/../../uploads/homework/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '', $f['name']);
                    $dst = 'uploads/homework/' . time() . '_' . $safeName;
                    $fullPath = __DIR__ . '/../../' . $dst;
                    if (move_uploaded_file($f['tmp_name'], $fullPath)) {
                        $uploadPath = $dst;
                    } else {
                        $errors[] = 'Failed to save attachment.';
                    }
                } else {
                    $errors[] = 'File is too large (max 10MB).';
                }
            } else {
                $errors[] = 'Unsupported file type.';
            }
        } else {
            $errors[] = 'Upload error occurred.';
        }
    }

    if (empty($errors)) {
        // Get eligible students based on latest active promotion as of assignment date
        $paramTypes = 'si';
        $paramValues = [$date_assigned, $group_id];
        $centreFilterSql = '';
        if ($centre_filter > 0) {
            $centreFilterSql = ' AND s.centre_id = ?';
            $paramTypes .= 'i';
            $paramValues[] = $centre_filter;
        }

        $sqlEligibleStudents = "
            SELECT s.id
            FROM students s
            JOIN (
                SELECT sp1.student_id, sp1.art_group_id
                FROM student_promotions sp1
                INNER JOIN (
                    SELECT student_id, MAX(effective_date) AS max_date
                    FROM student_promotions
                    WHERE is_applied = 1 AND effective_date <= ?
                    GROUP BY student_id
                ) sp2 ON sp1.student_id = sp2.student_id AND sp1.effective_date = sp2.max_date
                WHERE sp1.is_applied = 1
            ) cp ON cp.student_id = s.id
            WHERE cp.art_group_id = ? $centreFilterSql
        ";

        $stmt = $conn->prepare($sqlEligibleStudents);
        $stmt->bind_param($paramTypes, ...$paramValues);
        $stmt->execute();
        $eligibleIds = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
        $stmt->close();

        // Exclude students already assigned for this group, date, title
        $finalIds = [];
        if ($eligibleIds) {
            $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $sqlCheckAssigned = "SELECT student_id FROM homework_assigned WHERE art_group_id = ? AND date_assigned = ? AND title = ? AND student_id IN ($placeholders)";
            $stmt = $conn->prepare($sqlCheckAssigned);

            $typesCheck = 'iss' . str_repeat('i', count($eligibleIds));
            $paramsCheck = array_merge([$group_id, $date_assigned, $title], $eligibleIds);

            $stmt->bind_param($typesCheck, ...$paramsCheck);
            $stmt->execute();
            $alreadyAssigned = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'student_id');
            $stmt->close();

            $finalIds = array_diff($eligibleIds, $alreadyAssigned);
        }

        if ($finalIds) {
            // Bulk insert new assignments
            $sqlInsert = "INSERT INTO homework_assigned (student_id, art_group_id, date_assigned, title, description, file_path) VALUES ";
            $placeholdersArr = [];
            $insertTypes = '';
            $insertParams = [];
            foreach ($finalIds as $sid) {
                $placeholdersArr[] = '(?, ?, ?, ?, ?, ?)';
                $insertParams[] = $sid;
                $insertParams[] = $group_id;
                $insertParams[] = $date_assigned;
                $insertParams[] = $title;
                $insertParams[] = $description;
                $insertParams[] = $uploadPath;
                $insertTypes .= 'iissss';
            }
            $sqlInsert .= implode(', ', $placeholdersArr);
            $stmt = $conn->prepare($sqlInsert);
            $stmt->bind_param($insertTypes, ...$insertParams);
            $stmt->execute();
            $stmt->close();

            $success = "Assigned to ".count($finalIds)." student(s).";

            if (function_exists('create_notification')) {
                create_notification($conn, array_values($finalIds), 'New Homework Assignment',
                    "You have new homework assigned: '{$title}' for {$date_assigned}.");
            }

            if (function_exists('log_audit')) {
                log_audit($conn, $_SESSION['user_id'], 'CREATE', 'homework_batch', null, [
                    'group_id' => $group_id,
                    'title' => $title,
                    'date' => $date_assigned,
                    'student_count' => count($finalIds)
                ]);
            }
            $_SESSION['assign_history'][] = time();
        } else {
            $success = "No new eligible students found for this assignment.";
        }
    }
}

// Filter batch listing parameters
$filterWhere = '';
$filterParams = [];
$filterTypes = '';
if (isset($_GET['group_filter']) && $_GET['group_filter'] !== '') {
    $filterWhere .= ' AND ha.art_group_id = ?';
    $filterParams[] = (int)$_GET['group_filter'];
    $filterTypes .= 'i';
}
if (isset($_GET['date_filter']) && $_GET['date_filter'] !== '') {
    $filterWhere .= ' AND ha.date_assigned = ?';
    $filterParams[] = $_GET['date_filter'];
    $filterTypes .= 's';
}

$stmt = $conn->prepare("
    SELECT 
        ha.date_assigned,
        ha.title,
        ha.description,
        ha.file_path,
        ag.label AS group_label,
        ag.id AS group_id,
        COUNT(*) AS student_count,
        MIN(ha.id) AS batch_id,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') AS student_names,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS centre_names
    FROM homework_assigned ha
    JOIN art_groups ag ON ha.art_group_id = ag.id
    LEFT JOIN students s ON ha.student_id = s.id
    LEFT JOIN centres c ON s.centre_id = c.id
    WHERE 1=1 $filterWhere
    GROUP BY ha.date_assigned, ha.title, ha.description, ha.file_path, ag.id, ag.label
    ORDER BY ha.date_assigned DESC, ag.label, ha.title
");
if ($filterTypes) $stmt->bind_param($filterTypes, ...$filterParams);
$stmt->execute();
$assignmentBatches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Group-wise Homework Assignment</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet"/>
<style>
.assignment-card { transition: all 0.2s ease; }
.assignment-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
.student-count-badge { font-size: 0.85em; }
.group-badge { font-size: 0.9em; }
.preview-area { background: #f8f9fa; border-radius: 8px; min-height: 100px; }
.loading-spinner { display: none; }
.export-btn { position: fixed; bottom: 20px; right: 20px; z-index: 1000; }
.collapse, .collapse:not(.show) { visibility: visible !important; display: none !important; }
.collapse.show { display: block !important; visibility: visible !important; }
@media (max-width: 768px) {
  .export-btn { position: relative; bottom: auto; right: auto; margin-top: 20px; }
}
</style>
</head>
<body class="bg-light">
<div class="container py-5">

<h1>Group-wise Homework Assignment</h1>

<?php if ($success): ?>
  <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul>
    <?php foreach ($errors as $e): ?>
    <li><?= htmlspecialchars($e) ?></li>
    <?php endforeach; ?>
  </ul></div>
<?php endif; ?>

<!-- Filter form -->
<div class="card mb-4">
<div class="card-body">
<form method="get" class="row g-3">
  <input type="hidden" name="page" value="assign_homework" />
  <div class="col-md-3">
    <label>Filter by Group</label>
    <select name="group_filter" class="form-select">
      <option value="">All Groups</option>
      <?php foreach ($groups as $group): ?>
      <option value="<?= $group['id'] ?>" <?= ($_GET['group_filter'] ?? '') == $group['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($group['label']) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3">
    <label>Filter by Date</label>
    <input type="date" name="date_filter" class="form-control" value="<?= htmlspecialchars($_GET['date_filter'] ?? '') ?>">
  </div>
  <div class="col-md-4">
    <label>Search Assignments</label>
    <input type="text" id="searchInput" class="form-control" placeholder="Search by title, group, or student name...">
  </div>
  <div class="col-md-2 d-flex align-items-end">
    <button type="submit" class="btn btn-outline-primary w-100">Apply Filters</button>
  </div>
</form>
</div>
</div>

<!-- Assignment Form -->
<div class="card mb-4">
  <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-plus-circle"></i> Create New Assignment</h5></div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" id="assignmentForm" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="assign">

      <div class="col-md-4">
        <label class="form-label">Group</label>
        <select name="group_id" id="group_id" class="form-select" required>
          <option value="">Select group...</option>
          <?php foreach ($groups as $g): ?>
          <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Date</label>
        <input type="date" name="date_assigned" id="date_assigned" class="form-control" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Centre (optional)</label>
        <select name="centre_filter" id="centre_filter" class="form-select">
          <option value="">All Centres</option>
          <?php foreach ($centres as $c): ?>
          <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-6">
        <label class="form-label">Title</label>
        <input type="text" name="title" class="form-control" maxlength="255" required>
      </div>

      <div class="col-md-6">
        <label class="form-label">Attachment (optional)</label>
        <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
      </div>

      <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" rows="4" class="form-control" required></textarea>
      </div>

      <div class="col-12 text-end">
        <button type="submit" class="btn btn-primary btn-lg">Assign Homework</button>
      </div>
    </form>
  </div>
</div>

<!-- Assignment Batches List -->
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0"><i class="bi bi-list-ul"></i> Assignment Batches</h5>
    <span class="badge bg-secondary"><?= count($assignmentBatches) ?> batches</span>
  </div>
  <div class="card-body" id="assignmentsList">
    <?php if (empty($assignmentBatches)): ?>
      <div class="text-center text-muted py-5">
        <i class="bi bi-inbox display-1"></i>
        <h4>No assignments found</h4>
        <p>Create your first group assignment above.</p>
      </div>
    <?php else: ?>
      <?php foreach ($assignmentBatches as $index => $batch): ?>
      <div class="assignment-card card mb-3" data-searchable="<?= htmlspecialchars(strtolower($batch['title'] . ' ' . $batch['group_label'] . ' ' . $batch['student_names'])) ?>">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-md-8">
              <h6 class="card-title mb-2">
                <i class="bi bi-calendar3"></i>
                <?= htmlspecialchars($batch['date_assigned']) ?> —
                <strong><?= htmlspecialchars($batch['title']) ?></strong>
                <span class="badge bg-info ms-2 group-badge">
                  <?= htmlspecialchars($batch['group_label']) ?>
                </span>
              </h6>
              <p class="card-text text-muted mb-2"><?= nl2br(htmlspecialchars(substr($batch['description'], 0, 150))) ?><?= strlen($batch['description']) > 150 ? '...' : '' ?></p>
              <div class="d-flex align-items-center gap-3 mb-3">
                <span class="badge bg-success student-count-badge">
                  <i class="bi bi-people-fill"></i>
                  <?= $batch['student_count'] ?> students
                </span>
                <?php if ($batch['file_path']): ?>
                  <a href="/artovue/<?= htmlspecialchars($batch['file_path']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank">
                    <i class="bi bi-download"></i> Attachment
                  </a>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="collapse" data-bs-target="#studentList<?= $index ?>" aria-expanded="false">
                  <i class="bi bi-eye"></i> View Students
                </button>
              </div>
              <div class="collapse" id="studentList<?= $index ?>">
                <div class="card card-body bg-light">
                  <h6><i class="bi bi-people"></i> Assigned Students (<?= $batch['student_count'] ?>):</h6>
                  <?php if (!empty($batch['student_names'])): ?>
                    <div class="row">
                      <?php 
                      $students = array_filter(explode(', ', $batch['student_names']));
                      foreach ($students as $student): ?>
                        <div class="col-md-6 col-lg-4 mb-2">
                          <span class="badge bg-primary">
                            <i class="bi bi-person"></i> <?= htmlspecialchars(trim($student)) ?>
                          </span>
                        </div>
                      <?php endforeach; ?>
                    </div>
                    <?php if ($batch['centre_names']): ?>
                      <div class="mt-2">
                        <small class="text-muted"><i class="bi bi-building"></i> Centres: <?= htmlspecialchars($batch['centre_names']) ?></small>
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <p class="text-muted mb-0">No students found for this assignment.</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="col-md-4 text-end">
              <form method="post" onsubmit="return confirm('Delete this entire assignment batch? This cannot be undone.')" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="batch_id" value="<?= $batch['batch_id'] ?>">
                <button type="submit" class="btn btn-outline-danger">
                  <i class="bi bi-trash"></i> Delete Batch
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<button class="btn btn-secondary export-btn" onclick="exportToCSV()">
  <i class="bi bi-download"></i> Export CSV
</button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Search filter
  document.getElementById('searchInput')?.addEventListener('input', function() {
    const query = this.value.toLowerCase();
    document.querySelectorAll('.assignment-card').forEach(card => {
      card.style.display = card.dataset.searchable.includes(query) ? 'block' : 'none';
    });
  });

  // Export CSV function
  function exportToCSV() {
    const rows = [
      ['Date', 'Title', 'Group', 'Students', 'Student Count', 'Description']
    ];
    document.querySelectorAll('.assignment-card:visible').forEach(card => {
      const title = card.querySelector('.card-title strong').textContent.trim();
      const date = card.querySelector('.card-title').textContent.split('—')[0].trim();
      const group = card.querySelector('.group-badge').textContent.trim();
      const studentCount = card.querySelector('.student-count-badge').textContent.replace(/[^\d]/g, '');
      const description = card.querySelector('.card-text').textContent.trim().replace(/\s+/g, ' ');
      const students = Array.from(card.querySelectorAll('#'+card.querySelector('button[data-bs-toggle="collapse"]').getAttribute('data-bs-target').substr(1) + ' span.badge')).map(b => b.textContent.trim()).join('; ');
      rows.push([date, title, group, students, studentCount, description]);
    });

    const csvContent = rows.map(r => r.map(c => `"${c.replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'homework_assignments_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  }
</script>

<?php
// AJAX endpoint for student preview (same as original code you had)
// Place before </body> or handle with a separate PHP endpoint as preferred

if (isset($_GET['action']) && $_GET['action'] === 'preview') {
    $groupId = (int)($_GET['group_id'] ?? 0);
    $date = $_GET['date'] ?? '';
    $centreFilter = (int)($_GET['centre_filter'] ?? 0);

    if (!$groupId || !$date) {
        http_response_code(400);
        exit(json_encode(['error' => 'Missing group_id or date']));
    }

    $whereCentre = "";
    $paramTypes = "is";
    $paramValues = [$groupId, $date];

    if ($centreFilter > 0) {
        $whereCentre = " AND s.centre_id = ?";
        $paramTypes .= "i";
        $paramValues[] = $centreFilter;
    }

    // Eligible (Not yet assigned)
$sql = "
    SELECT s.id
    FROM students s
    JOIN (
        SELECT sp1.student_id, sp1.art_group_id
        FROM student_promotions sp1
        INNER JOIN (
            SELECT student_id, MAX(effective_date) AS max_date
            FROM student_promotions
            WHERE is_applied = 1 AND effective_date <= ?
            GROUP BY student_id
        ) sp2 ON sp1.student_id = sp2.student_id AND sp1.effective_date = sp2.max_date
        WHERE sp1.is_applied = 1
    ) curpromo ON curpromo.student_id = s.id
    WHERE curpromo.art_group_id = ? 
    $whereCentre
    AND s.id NOT IN (
        SELECT student_id 
        FROM homework_assigned 
        WHERE art_group_id = ? AND date_assigned = ?
    )
";

// Add additional parameters for the "NOT IN" subquery
$paramTypes .= 'iss';
$paramValues[] = $groupId;      // art_group_id for assigned homework
$paramValues[] = $date_assigned; // date_assigned for assigned homework

// Prepare statement
$stmt = $conn->prepare($sql);

// Merge all parameters in correct order
$allParams = $paramValues;

// Bind all parameters safely
$stmt->bind_param($paramTypes, ...$allParams);

// Execute and fetch eligible students
$stmt->execute();
$eligibleStudents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

    header('Content-Type: application/json');
    echo json_encode(['eligible' => $eligible, 'excluded' => $excluded]);
    exit;
}
?>

</body>
</html>
