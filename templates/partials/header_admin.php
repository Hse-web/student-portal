<?php
// templates/partials/header_admin.php
if (!isset($adminUsername)) {
  $adminUsername = $_SESSION['username'] ?? 'Admin';
}
if (!isset($menu) || !is_array($menu)) {
  $menu = [];
}
$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Panel</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
    rel="stylesheet"
  >
  <link
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
    rel="stylesheet"
  >
  <style>
    body { background:#f8f9fa; }
    .topbar { background:#343a40; }
    .topbar .navbar-brand, .topbar .text-light { color:#fff; }
    .sidebar { background:#fff; border-right:1px solid #ddd; min-height:100vh; }
    .nav-link.active { background:#0d6efd; color:#fff!important; }
  </style>
</head>
<body>
<nav class="navbar topbar px-4 py-2">
  <span class="navbar-brand">
    <i class="bi bi-shield-lock-fill me-2"></i>Admin Panel
  </span>
  <div class="d-flex align-items-center">
    <span class="text-light me-3"><?=htmlspecialchars($adminUsername)?></span>
    <a href="../logout.php" class="btn btn-outline-light btn-sm">Logout</a>
  </div>
</nav>
<div class="container-fluid">
  <div class="row">
    <nav class="col-md-2 d-none d-md-block sidebar p-3">
      <ul class="nav flex-column">
        <?php foreach($menu as $item):
          if(empty($item['url'])||empty($item['label'])) continue;
          $active = basename($item['url'])=== $currentPage ? ' active':'';
        ?>
          <li class="nav-item mb-2">
            <a href="<?=$item['url']?>" class="nav-link<?=$active?>">
              <?php if($item['icon']):?>
                <i class="bi <?=$item['icon']?> me-2"></i>
              <?php endif;?>
              <?=htmlspecialchars($item['label'])?>
            </a>
          </li>
        <?php endforeach;?>
      </ul>
    </nav>
    <main class="col-md-10 py-4">
