<?php
// dashboard/admin/_admin_nav.php
// expects $adminUsername, $menu and $page to already be defined

?>
<!-- 🌈 Top Bar -->
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
    <!-- 📚 Sidebar -->
    <nav class="col-md-2 d-none d-md-block sidebar p-3">
      <ul class="nav flex-column">
      <?php foreach($menu as $key=>[$icon,$label]): ?>
        <li class="nav-item mb-2">
          <a href="./?page=<?= $key ?>"
             class="nav-link <?= $page=== $key ? 'active':'' ?>">
            <i class="bi <?= $icon ?> me-2"></i><?= $label ?>
          </a>
        </li>
      <?php endforeach; ?>
      </ul>
    </nav>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- 📄 Main Content -->
    <main class="col-md-10 py-4">
