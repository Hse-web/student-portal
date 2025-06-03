<?php
// templates/partials/header.php
// Must set before include:
//   $title        = page title (string)
//   $_SESSION[...] for auth
  $role         = 'admin' or 'student'
//  $adminUsername (for admin pages)
//  $menu, $page  (for admin pages nav)

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($title) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
     <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet">
  <!-- any other <head> bits -->
</head>
<body class="bg-light">

<?php if ($role==='admin'): ?>
  <?php include __DIR__ . '/header_admin.php'; ?>
<?php else: /* student */ ?>
  <?php include __DIR__ . '/header_student.php'; ?>
<?php endif; ?>

<div class="container-fluid">
  <div class="row">
    <!-- main content column for both roles -->
    <main class="col-md-10 offset-md-2 py-4">
