<?php
// dashboard/student/_student_nav.php
// Expects $username, $studentName, $menu and $page in scope
?>
<nav class="navbar topbar">
  <div class="container-fluid d-flex justify-content-between">
    <span class="navbar-brand text-white">
      <i class="bi bi-person-circle me-2"></i>Welcome, <?= htmlspecialchars($studentName) ?>
    </span>
    <div class="d-flex align-items-center">
      <a href="/student-portal/logout.php" class="btn btn-outline-light btn-sm">
        Logout
      </a>
    </div>
  </div>
</nav>

<div class="container-fluid">
  <div class="row">
    <nav class="col-md-2 d-none d-md-block sidebar p-3">
      <ul class="nav flex-column">
        <?php foreach($menu as $key=>[$icon,$label]): ?>
          <li class="nav-item mb-2">
            <a href="?page=<?= $key ?>"
               class="nav-link <?= $page=== $key ? 'active':'' ?>">
              <i class="bi <?= $icon ?> me-2"></i><?= htmlspecialchars($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </nav>
