<?php
// templates/partials/header_admin.php
// Expects $menu, $page, and $adminUsername to already be defined
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
    .topbar { background: #343a40; color: #fff; padding: .75rem 1.5rem; }
    .sidebar { background: #fff; min-height: 100vh; border-right: 1px solid #ddd; }
    .nav-link.active { background: #007bff; color: #fff !important; }
    .card-hover { transition: transform .2s, box-shadow .2s; }
    .card-hover:hover { transform: translateY(-5px); box-shadow: 0 5px 15px rgba(0,0,0,.1); }
    .section-header { margin: 2rem 0 1rem; color: #343a40; font-weight: 600; }
  </style>
</head>
<body>
  <nav class="navbar topbar">
    <div class="container-fluid d-flex justify-content-between">
      <span class="navbar-brand text-white">
        <i class="bi bi-shield-lock-fill me-2"></i>Admin Panel
      </span>
      <div class="d-flex align-items-center">
        <span class="text-white me-3"><?= htmlspecialchars($adminUsername) ?></span>
        <a href="/student-portal/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
      </div>
    </div>
  </nav>
  <div class="container-fluid">
    <div class="row">
      <nav class="col-md-2 d-none d-md-block sidebar p-3">
        <ul class="nav flex-column">
          <?php foreach($menu as $key => [$icon, $label]): ?>
            <li class="nav-item mb-2">
              <a href="?page=<?= $key ?>"
                 class="nav-link <?= $page === $key ? 'active':'' ?>">
                <i class="bi <?= $icon ?> me-2"></i><?= htmlspecialchars($label) ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </nav>
      <main class="col-md-10 py-4">
