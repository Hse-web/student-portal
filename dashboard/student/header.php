<?php
// File: dashboard/student/header.php
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Student Portal – Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="manifest" href="/manifest.json">
  <meta name="theme-color" content="#6b21a8">
  <link rel="icon" href="/assets/logo-rartworks.png">

  <!-- Tailwind + custom palette -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'student-primary':   '#6b21a8',
            'student-secondary': '#9333ea',
            'accent-yellow':     '#fbbf24',
            'accent-green':      '#10b981',
          }
        }
      }
    };
  </script>

  <!-- Bootstrap & Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>

  <style>
    /* Enforce our purple secondary */
    .bg-secondary { background-color: #9333ea !important; }
    .text-white    { color: #fff !important;      }
    a { text-decoration: none !important; }
  </style>
</head>
<body class="flex flex-col min-h-screen bg-gray-100">

  <!-- Toast container (for toasts) -->
  <div aria-live="polite" aria-atomic="true"
       class="position-fixed top-0 end-0 p-3" style="z-index:1080;">
    <div id="toast-container"></div>
  </div>

  <!-- MOBILE TOPBAR -->
  <nav class="md:hidden bg-student-primary text-white p-3 flex items-center justify-between shadow">
    <button id="mobileMenuToggle"><i class="bi bi-list text-2xl"></i></button>
    <img src="/assets/logo-icon.svg" alt="Artovue" class="h-8"/>
    <div class="flex items-center space-x-3">
      <a href="?page=notifications" class="position-relative">
        <i class="bi bi-bell-fill text-2xl"></i>
        <?php if (!empty($notifUnread)): ?>
          <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
            <?= $notifUnread ?>
          </span>
        <?php endif; ?>
      </a>
      <a href="/artovue/logout.php"><i class="bi bi-box-arrow-right text-2xl"></i></a>
    </div>
  </nav>

  <!-- MOBILE MENU -->
  <div id="mobileMenu" class="hidden bg-secondary text-white shadow-lg">
    <ul class="divide-y divide-gray-700">
      <?php foreach($studentMenu as $key => [$icon,$label,$href]):
        $active = $page === $key ? 'bg-student-primary' : 'hover:bg-student-primary/90';
      ?>
        <li>
          <a href="<?= $href ?>"
             class="flex items-center p-3 <?= $active ?>">
            <i class="bi <?= $icon ?> me-3"></i><?= htmlspecialchars($label) ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="flex flex-1">
    <!-- DESKTOP SIDEBAR -->
    <aside class="hidden md:block w-64 bg-secondary text-white p-4 shadow-lg">
      <div class="mb-6 text-center">
        <img src="/assets/logo-full-color.svg" alt="Artovue" class="w-32 mx-auto mb-4"/>
        <div class="font-bold text-xl">Student Portal</div>
      </div>
      <ul class="space-y-2">
        <?php foreach($studentMenu as $key => [$icon,$label,$href]):
          $active = $page === $key ? 'bg-primary text-white' : 'hover:bg-primary/90';
        ?>
          <li>
            <a href="<?= $href ?>"
               class="flex items-center p-2 rounded-lg <?= $active ?>">
              <i class="bi <?= $icon ?> me-3"></i><?= htmlspecialchars($label) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </aside>

    <div class="flex-1 flex flex-col">
      <!-- DESKTOP TOPBAR -->
      <header class="hidden md:flex bg-student-primary text-white px-6 py-3 justify-between shadow">
        <div class="flex items-center space-x-4">
          <img src="/assets/logo-icon.svg" alt="Artovue" class="h-8"/>
          <?php
            $h = (int)date('H');
            $g = $h<12 ? 'Good morning' : ($h<18 ? 'Good afternoon' : 'Good evening');
          ?>
          <span class="font-semibold"><?= $g ?>, <?= htmlspecialchars($studentName) ?>!</span>
        </div>
        <div class="flex items-center space-x-4">
          <a href="?page=notifications" class="position-relative">
            <i class="bi bi-bell-fill text-2xl"></i>
            <?php if (!empty($notifUnread)): ?>
              <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
                <?= $notifUnread ?>
              </span>
            <?php endif; ?>
          </a>
          <a href="/artovue/logout.php"
             class="border border-white px-3 py-1 rounded hover:bg-white hover:text-gray-800 transition">
            Logout
          </a>
        </div>
      </header>

      <!-- MAIN CONTENT STARTS -->
      <main class="flex-1 overflow-auto p-6">
