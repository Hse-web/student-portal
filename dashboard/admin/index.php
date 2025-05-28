<?php
// File: dashboard/admin/index.php

// ─── 1) Auth & Bootstrap ─────────────────────────────────────────────
ob_start();
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── 2) Pre–handle any POST actions *before* we sanitise $page ────────
if (isset($_GET['page']) && $_GET['page']==='delete_student') {
    include __DIR__.'/delete_student.php';
    exit;
}
if (isset($_GET['page']) && $_GET['page']==='delete_bulk' 
    && $_SERVER['REQUEST_METHOD']==='POST') {
    include __DIR__.'/delete_bulk.php';
    exit;
}
if (isset($_GET['page']) && $_GET['page']==='add_student'
    && $_SERVER['REQUEST_METHOD']==='POST') {
    include __DIR__.'/add_student.php';
    exit;
}
if (isset($_GET['page']) && $_GET['page']==='edit_student'
    && isset($_GET['id'])
    && $_SERVER['REQUEST_METHOD']==='POST') {
    include __DIR__.'/edit_student.php';
    exit;
}
// ─── 2) Sidebar menu & current page ─────────────────────────────────
$menu = [
    'dashboard'           => ['bi-speedometer2','Overview'],
    'students'            => ['bi-people-fill','Students'],
    'add_student'         => ['bi-person-plus','Add Student'],
    'edit_student'        => ['bi-pencil-square','Edit Student'],
    'attendance'          => ['bi-calendar-check','Attendance'],
    'assign_homework'     => ['bi-journal-text','Assign Homeworks'],
    'homework_centerwise' => ['bi-journal-text','Homework Submissions'],
    'progress'            => ['bi-pencil-square','Progress'],
    'admin_payment'       => ['bi-currency-rupee','Payments'],
    'comp_requests'       => ['bi-currency-rupee','Compensation requests'],
    'video_completions'       => ['bi-collection-play-fill','video_completions'],
    'video_manager'       => ['bi-play-btn-fill','video_manager'],
    
];
$page = $_GET['page'] ?? 'dashboard';
if (!isset($menu[$page])) {
    $page = 'dashboard';
}

// ─── 4) Fetch admin username ─────────────────────────────────────────
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();

// ─── 5) Dashboard counts (for overview cards) ────────────────────────
$tableMap = ['students'=>'students'];
$counts = [];
foreach ($tableMap as $key => $tbl) {
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM `$tbl`")->fetch_assoc();
    $counts[$key] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Panel</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet">
  <style>
    body { background: #f8f9fa; }
    .topbar { background: #343a40; color: #fff; }
    .sidebar { background: #fff; border-right: 1px solid #ddd; }
    .nav-link.active { background: #007bff; color: #fff; }
    .card-hover { transition: transform .2s, box-shadow .2s; }
    .card-hover:hover { transform: translateY(-5px);
                        box-shadow: 0 5px 15px rgba(0,0,0,.1); }
    .section-header { margin: 1.5rem 0; color: #343a40; font-weight: 600; }
  </style>
</head>
<body>

  <!-- Topbar -->
  <nav class="navbar topbar px-4">
    <span class="navbar-brand text-white">
      <i class="bi bi-shield-lock-fill me-2"></i>Admin Panel
    </span>
    <div class="ms-auto text-white">
      <?= htmlspecialchars($adminUsername) ?>
      <a href="/student-portal/logout.php"
         class="btn btn-outline-light btn-sm ms-3">Logout</a>
    </div>
  </nav>

  <div class="container-fluid">
    <div class="row">

      <!-- Sidebar -->
      <nav class="col-md-2 d-none d-md-block sidebar p-3">
        <ul class="nav flex-column">
          <?php foreach ($menu as $key => [$icon,$label]): ?>
            <li class="nav-item mb-2">
              <a href="?page=<?= $key ?>"
                 class="nav-link <?= $page === $key ? 'active':'' ?>">
                <i class="bi <?= $icon ?> me-2"></i><?= htmlspecialchars($label) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <!-- Main Content -->
      <main class="col-md-10 py-4">

        <?php switch ($page):
          // ─── Overview ───────────────────────────────────────────────
          case 'dashboard': ?>
            <h2 class="section-header">Overview</h2>
            <div class="row g-4">
              <?php foreach ($tableMap as $key => $_): ?>
              <div class="col-6 col-md-4">
                <div class="card card-hover text-center p-3">
                  <i class="bi <?= $menu[$key][0] ?> fs-1"></i>
                  <h5 class="mt-2"><?= $menu[$key][1] ?></h5>
                  <div class="display-5"><?= $counts[$key] ?></div>
                  <a href="?page=<?= $key ?>" class="stretched-link"></a>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php break;

          // ─── Students List ────────────────────────────────────────
          case 'students':
            include __DIR__ . '/students.php';
            break;

          // ─── Add / Edit / Attendance / Payments etc ──────────────
          case 'add_student':
            include __DIR__ . '/add_student.php';
            break;

          case 'edit_student':
            include __DIR__ . '/edit_student.php';
            break;

          case 'attendance':
            include __DIR__ . '/attendance.php';
            break;

          case 'assign_homework':
            include __DIR__ . '/assign_homework.php';
            break;

          case 'homework_centerwise':
            include __DIR__ . '/homework_centerwise.php';
            break;

          case 'progress':
            include __DIR__ . '/progress.php';
            break;

          case 'admin_payment':
            include __DIR__ . '/admin_payment.php';
            break;

             case 'comp_requests':
            include __DIR__ . '/comp_requests.php';
            break;

             case 'video_completions':
            include __DIR__ . '/video_completions.php';
            break;

             case 'video_manager':
            include __DIR__ . '/video_manager.php';
            break;

          default:
            echo '<div class="alert alert-warning">Page not found.</div>';
        endswitch; ?>

      </main>
    </div>
  </div>

  <script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
