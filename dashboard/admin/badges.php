<?php
// File: dashboard/admin/badges.php
// Included via admin/index.php?page=badges

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';

// ─── Handle Assign/Revoke ────────────────────────────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $student = (int)($_POST['student_id'] ?? 0);
  $badge   = (int)($_POST['badge_id']   ?? 0);
  $action  = $_POST['action'] ?? '';

  if ($student && $badge && in_array($action, ['assign','revoke'], true)) {
    if ($action === 'assign') {
      $stmt = $conn->prepare("INSERT IGNORE INTO user_badges (student_id,badge_id) VALUES (?,?)");
      $stmt->bind_param('ii', $student, $badge);
      $flash = '🏅 Badge assigned.';
    } else {
      $stmt = $conn->prepare("DELETE FROM user_badges WHERE student_id=? AND badge_id=?");
      $stmt->bind_param('ii', $student, $badge);
      $flash = '↩️ Badge revoked.';
    }
    $stmt->execute();
    $stmt->close();
  } else {
    $flash = '⚠️ Please select a student, a badge, and an action.';
  }
}

// ─── Fetch Data ───────────────────────────────────────────────────────────
$students = $conn
  ->query("SELECT id,name FROM students ORDER BY name")
  ->fetch_all(MYSQLI_ASSOC);

$badges = $conn
  ->query("SELECT id,label,icon,tier FROM badges ORDER BY tier, label")
  ->fetch_all(MYSQLI_ASSOC);

$rows = $conn
  ->query("SELECT student_id,badge_id FROM user_badges")
  ->fetch_all(MYSQLI_ASSOC);

$userBadges = [];
foreach ($rows as $r) {
  $userBadges[$r['student_id']][$r['badge_id']] = true;
}

// ────────────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Admin → Badges</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            primary:   '#6b21a8',
            secondary: '#9333ea',
          }
        }
      }
    };
  </script>
  <style>
    /* match your admin theme */
    .sidebar { background-color: #9333ea; }
    .topbar  { background-color: #6b21a8; }
    a { text-decoration: none; }
  </style>
</head>
<body class="flex">

  <div class="flex-1 flex flex-col">
    <main class="p-6 flex-1 overflow-auto">

      <!-- flash -->
      <?php if($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>

      <!-- form -->
      <form method="POST" class="row g-3 align-items-end mb-6">
        <div class="col-md-4">
          <label class="form-label">Student</label>
          <select name="student_id" class="form-select" required>
            <option value="">— Select Student —</option>
            <?php foreach($students as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Badge</label>
          <select name="badge_id" class="form-select" required>
            <option value="">— Select Badge —</option>
            <?php foreach($badges as $b): ?>
              <option value="<?= $b['id'] ?>">
                <?= htmlspecialchars($b['label']) ?> (<?= ucfirst($b['tier']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
          <button name="action" value="assign" class="btn btn-success w-50">
            <i class="bi bi-plus-circle"></i> Assign
          </button>
          <button name="action" value="revoke" class="btn btn-danger w-50">
            <i class="bi bi-dash-circle"></i> Revoke
          </button>
        </div>
      </form>

      <!-- table -->
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead class="table-dark">
            <tr>
              <th>Student</th>
              <?php foreach($badges as $b): ?>
                <th class="text-center">
                  <i class="bi <?= $b['icon'] ?> fs-4"></i><br>
                  <small><?= htmlspecialchars($b['label']) ?></small>
                </th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach($students as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <?php foreach($badges as $b): ?>
                  <td class="text-center">
                    <?php if(!empty($userBadges[$s['id']][$b['id']])): ?>
                      <i class="bi bi-check-circle-fill text-success fs-5"></i>
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    </main>

    <footer class="text-center py-3 border-t">
      &copy; <?= date('Y') ?> <strong>Artovue</strong> · Powered by Rart Works
    </footer>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
