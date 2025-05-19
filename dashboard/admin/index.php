<?php
// dashboard/admin/index.php

session_start();
require_once __DIR__ . '/../../config/db.php';

// ─── Auth guard ─────────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../../login/index.php');
    exit;
}

// ─── Which page are we on? ──────────────────────────────────────────
$menu = [
    'dashboard'       => ['bi-speedometer2','Overview'],
    'students'        => ['bi-people-fill','Students'],
    'attendance'        => ['bi-people-fill','Attendance'],
    'assign_homework'        => ['bi-people-fill','Assign Homeworks'],
    'homework_centerwise' => ['bi-journal-text', 'Homework Submissions'],
    'add_student'     => ['bi-person-plus','Add Student'],
    'progress'    => ['bi-pencil-square','progress'],
    'admin_payment'   => ['bi-currency-rupee','Payments'],
    // …and whatever else you need…
];
$page = $_GET['page'] ?? 'dashboard';
if (! array_key_exists($page, $menu)) {
    $page = 'dashboard';
}

// ─── Fetch admin username ───────────────────────────────────────────
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$stmt->bind_result($adminUsername);
$stmt->fetch();
$stmt->close();

// ─── Handle “delete_student” immediately ────────────────────────────
if ($page === 'delete_student') {
    include __DIR__ . '/delete_student.php';
    exit;
}

// ─── Handle “add_student” form POST before any HTML ────────────────
if ($page === 'add_student' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    include __DIR__ . '/add_student.php';  // your add-logic lives here and ends with header('Location:...')+exit
}

// ─── Handle “edit_student” form POST before any HTML ───────────────
if ($page === 'edit_student' && isset($_GET['id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    include __DIR__ . '/edit_student.php'; // your edit-logic lives here and ends with header('Location:...')+exit
}

// ─── Counts for overview cards ─────────────────────────────────────
$tableMap = [
    'students' => 'students',
    // add more tables if you like
];
$counts = [];
foreach ($tableMap as $k => $tbl) {
    $row = $conn->query("SELECT COUNT(*) AS cnt FROM `$tbl`")->fetch_assoc();
    $counts[$k] = (int)$row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admin Panel</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <style>
    body { background: #f8f9fa; }
    .topbar { background: #343a40; color: #fff; padding: .75rem 1.5rem; }
    .sidebar { background: #fff; min-height: 100vh; border-right: 1px solid #ddd; }
    .nav-link.active { background: #007bff; color: #fff; }
    .card-hover { transition: transform .2s, box-shadow .2s; }
    .card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,.1); }
    .section-header { margin: 2rem 0 1rem; color: #343a40; font-weight: 600; }
  </style>
</head>
<body>

  <!-- Topbar -->
  <nav class="navbar topbar">
    <div class="container-fluid d-flex justify-content-between">
      <span class="navbar-brand text-white">
        <i class="bi bi-shield-lock-fill me-2"></i>Admin Panel
      </span>
      <div class="d-flex align-items-center">
        <span class="text-white me-3"><?= htmlspecialchars($adminUsername) ?></span>
        <a href="../../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
      </div>
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
          case 'dashboard': ?>
            <h2 class="section-header">Overview</h2>
            <div class="row g-4">
              <?php foreach ($tableMap as $key=>$_): ?>
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

          case 'students':
            include __DIR__ . '/students.php';
            break;

          case 'add_student':
            // the POST above already processed and redirected,
            // if you fall through to here it’s a GET show
            include __DIR__ . '/add_student.php';
            break;

            case 'attendance':
              // the POST above already processed and redirected,
              // if you fall through to here it’s a GET show
              include __DIR__ . '/attendance.php';
              break;

              case 'assign_homework':
                // the POST above already processed and redirected,
                // if you fall through to here it’s a GET show
                include __DIR__ . '/assign_homework.php';
                break;

                 case 'homework_centerwise':
                // the POST above already processed and redirected,
                // if you fall through to here it’s a GET show
                include __DIR__ . '/homework_centerwise.php';
                break;

                case 'progress':
                  // the POST above already processed and redirected,
                  // if you fall through to here it’s a GET show
                  include __DIR__ . '/progress.php';
                  break;

          case 'admin_payment':
            include __DIR__ . '/admin_payment.php';
            break;

          default:
            echo '<div class="alert alert-warning">Page not found.</div>';
        endswitch; ?>
      </main>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
