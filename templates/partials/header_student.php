<?php
// templates/partials/header_student.php
// Expects in scope: $_SESSION['username'], $menu (array of ['url','icon','label']), $page

$username   = $_SESSION['username'] ?? 'Student';
$current    = basename($_SERVER['SCRIPT_NAME']);

// Default menu if none passed in:
if (!isset($menu) || !is_array($menu)) {
    $menu = [
        ['url'=>'index.php','icon'=>'bi-house-fill','label'=>'Home'],
        ['url'=>'attendance.php','icon'=>'bi-calendar-check','label'=>'Attendance'],
        ['url'=>'compensation.php','icon'=>'bi-clock-history','label'=>'Compensation'],
        ['url'=>'homework.php','icon'=>'bi-journal-text','label'=>'Homework'],
        ['url'=>'stars.php','icon'=>'bi-stars','label'=>'Stars'],
        ['url'=>'notifications.php','icon'=>'bi-bell-fill','label'=>'Notifications'],
        ['url'=>'progress.php','icon'=>'bi-bar-chart-fill','label'=>'Progress'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Student Portal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet">
  <style>
    body { background:#f8f9fa; }
    .topbar { background:#007bff; color:#fff; }
    .sidebar { background:#fff; border-right:1px solid #ddd; min-height:100vh; }
    .nav-link.active { background:#0056b3; color:#fff!important; }
  </style>
</head>
<body>

<nav class="navbar topbar px-4 py-2">
  <span class="navbar-brand text-white">
    <i class="bi bi-person-circle me-2"></i>
    Welcome, <?= htmlspecialchars($studentName) ?>
  </span>
  <a href="../../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
</nav>

<div class="container-fluid">
  <div class="row">
    <nav class="col-md-2 d-none d-md-block sidebar p-3">
      <ul class="nav flex-column">
        <?php foreach($menu as $item):
          $active = basename($item['url'])=== $current ? ' active':'';
        ?>
          <li class="nav-item mb-2">
            <a href="<?= htmlspecialchars($item['url']) ?>"
               class="nav-link<?= $active ?>">
              <i class="bi <?= htmlspecialchars($item['icon']) ?> me-2"></i>
              <?= htmlspecialchars($item['label']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
    <main class="col-md-10 py-4">
