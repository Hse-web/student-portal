<?php
// File: config/bootstrap.php
// ───────────────────────────────────────────────────────────────────
// This file does three jobs for every dashboard page (admin or student):
//   1) session_start() + load session‐helper & audit‐helper
//   2) load the database connection (mysqli via db.php → $conn)
//   3) define four layout functions:
//       • require_role($role)
//       • render_admin_header($pageTitle)  / render_admin_footer()
//       • render_student_header($pageTitle) / render_student_footer()
// ───────────────────────────────────────────────────────────────────

// 1) Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2) Load any “session helper” or “audit helper” files you already have.
//    In our repo, session.php defines require_role(), 
//    generate_csrf_token(), verify_csrf_token(), and log_audit().
require_once __DIR__ . '/session.php';

// 3) Load the database connection; this creates a global $conn.
require_once __DIR__ . '/db.php';

////////////////////////////////////////////////////////////////////////////////
// 4) require_role($role) 
//    — Checks that the currently logged‐in user has exactly that role. 
//      If not, redirects to the login page.
////////////////////////////////////////////////////////////////////////////////
function require_role(string $role): void {
    if (
        empty($_SESSION['logged_in']) ||
        (($_SESSION['role'] ?? '') !== $role)
    ) {
        header('Location: /student-portal/login.php');
        exit;
    }
}

////////////////////////////////////////////////////////////////////////////////
// 5) render_admin_header($pageTitle) / render_admin_footer()
//    — Emits the common <head> … </body></html> wrapper for any admin page. 
//      Every file under dashboard/admin/ should do:
//          require_role('admin');
//          render_admin_header('…some title…');
//              … (page‐specific HTML/PHP) …
//          render_admin_footer();
//    — Inside this <head> we pull in Tailwind, Bootstrap Icons, etc.
//    — The “sidebar” is hard‐coded to show exactly the same menu items an
//      admin should see.  We look at $_GET['page'] to highlight the current item.
////////////////////////////////////////////////////////////////////////////////
function render_admin_header(string $pageTitle): void
{
    global $conn;

    // 1) Confirm the admin is logged in
    if (empty($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'admin')) {
        header('Location: /student-portal/login.php');
        exit;
    }

    // 2) Fetch the admin’s username for the topbar
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($adminUsername);
    $stmt->fetch();
    $stmt->close();

    // 3) Determine which “page” is active (for highlighting in the sidebar)
    $currentPage = $_GET['page'] ?? 'dashboard';

    // 4) Output the common <head> and open <body> + sidebar + topbar
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8" />
      <title><?= htmlspecialchars($pageTitle) ?></title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />

      <!-- Tailwind CSS (JIT via CDN) -->
      <script src="https://cdn.tailwindcss.com"></script>
      <script>
        // Inline Tailwind config for custom admin colors
        tailwind = window.tailwind || {};
        tailwind.config = {
          theme: {
            extend: {
              colors: {
                'admin-primary':   '#9C27B0',
                'admin-secondary': '#FFC107'
              }
            }
          }
        };
      </script>

      <!-- Bootstrap CSS (for responsive grid, forms, etc.) -->
      <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
      />

      <!-- Bootstrap Icons (for the sidebar icons) -->
      <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"
      />

      <!-- Custom admin CSS (if any) -->
      <link href="/student-portal/assets/custom.css" rel="stylesheet" />

      <style>
        /* Remove link underlines */
        a { text-decoration: none; }
      </style>
    </head>

    <body class="bg-gray-100 min-h-screen flex">

      <!-- ─── Mobile Topbar with Hamburger ───────────────────────────────────────── -->
      <nav class="md:hidden bg-admin-primary text-white p-3 flex items-center justify-between">
        <button id="adminSidebarToggle" class="text-white">
          <i class="bi bi-list text-2xl"></i>
        </button>
        <span class="text-lg font-semibold">Admin Panel</span>
        <a href="/student-portal/logout.php" class="text-white">
          <i class="bi bi-box-arrow-right text-xl"></i>
        </a>
      </nav>

      <!-- ─── Sidebar ─────────────────────────────────────────────────────────────── -->
      <aside id="adminSidebar"
             class="hidden md:block w-64 bg-admin-secondary text-gray-900 p-4 transition-transform duration-200 ease-in-out">
        <ul class="space-y-2">
          <?php
          // Define exactly the same array that every admin page uses to build its menu:
          $menuItems = [
            'dashboard'           => ['bi-speedometer2',         'Overview'],
            'students'            => ['bi-people-fill',          'Students'],
            'add_student'         => ['bi-person-plus',          'Add Student'],
            'edit_student'        => ['bi-pencil-square',        'Edit Student'],
            'attendance'          => ['bi-calendar-check',       'Attendance'],
            'assign_homework'     => ['bi-journal-text',         'Assign Homework'],
            'homework_centerwise' => ['bi-journal-text',         'Homework Submissions'],
            'progress'            => ['bi-pencil-square',        'Progress'],
            'admin_payment'       => ['bi-currency-rupee',       'Payments'],
            'comp_requests'       => ['bi-currency-rupee',       'Comp Requests'],
            'star_manager'        => ['bi-star-fill',            'Star Manager'],
            'video_completions'   => ['bi-collection-play-fill', 'Video Completions'],
            'video_manager'       => ['bi-play-btn-fill',        'Video Manager'],
          ];

          foreach ($menuItems as $key => [$iconClass, $labelText]) {
              $isActive = ($currentPage === $key);
              $linkClass = $isActive
                         ? 'bg-admin-primary text-white'
                         : 'text-gray-800 hover:bg-admin-primary hover:text-white focus:bg-admin-primary focus:text-white';

              $ariaCurrent = $isActive ? ' aria-current="page"' : '';
              echo "<li>
                      <a href=\"?page={$key}\"
                         class=\"flex items-center p-2 rounded-lg transition {$linkClass}\"{$ariaCurrent}>
                        <i class=\"bi {$iconClass} mr-3 text-lg\"></i>
                        <span class=\"font-medium\">{$labelText}</span>
                      </a>
                    </li>";
          }
          ?>
        </ul>
      </aside>

      <!-- ─── Main Container (Sidebar + Content) ───────────────────────────────── -->
      <div class="flex-1 flex flex-col">
        <!-- ─── Topbar (desktop) ───────────────────────────────────────────────── -->
        <header class="hidden md:flex bg-admin-primary text-white">
          <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center space-x-2 text-lg font-semibold">
              <i class="bi bi-shield-lock-fill"></i>
              <span>Admin Panel</span>
            </div>
            <div class="flex items-center space-x-4">
              <span><?= htmlspecialchars($adminUsername) ?></span>
              <a href="/student-portal/logout.php"
                 class="px-3 py-1 border border-white rounded-lg hover:bg-white hover:text-gray-800 transition">
                Logout
              </a>
            </div>
          </div>
        </header>

        <!-- ─── Begin “page‐specific” content; every caller will wrap in <main>… </main> ─── -->
        <main class="flex-1 p-6 overflow-auto">

          <!-- Page content will be injected by the caller. -->
    <?php
}

////////////////////////////////////////////////////////////////////////////////
// 6) render_admin_footer()
//    — Closes the <main>, prints a site‐wide footer, and closes </body></html>.
////////////////////////////////////////////////////////////////////////////////
function render_admin_footer(): void
{
    ?>
        </main>

        <!-- ─── Site‐Wide Footer ─────────────────────────────────────────────────── -->
        <footer class="bg-admin-secondary text-gray-900 text-center text-sm py-4">
          &copy; <?= date('Y') ?> Rart Works
        </footer>
      </div>  <!-- /.flex-1 flex flex-col -->
    </body>

    <!-- ─── Optional JS: Toggle Sidebar on Mobile ────────────────────────────────── -->
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('adminSidebarToggle');
        const sidebar = document.getElementById('admintSidebar');

        toggle.addEventListener('click', function() {
          if (sidebar.classList.contains('hidden')) {
            sidebar.classList.remove('hidden');
            sidebar.classList.add('block');
          } else {
            sidebar.classList.remove('block');
            sidebar.classList.add('hidden');
          }
        });
      });
    </script>
    </html>
    <?php
}

////////////////////////////////////////////////////////////////////////////////
// 7) render_student_header($pageTitle) / render_student_footer()
//    — Exactly the same idea as admin, but with a “student” color scheme
//      and a different sidebar structure.  Any file under dashboard/student/
//      will do:
//         require_role('student');
//         render_student_header('…some title…');
//            … (page content) …
//         render_student_footer();
////////////////////////////////////////////////////////////////////////////////
function render_student_header(string $pageTitle): void
{
    global $conn;

    // 1) Confirm the student is logged in
    if (empty($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'student')) {
        header('Location: /student-portal/login.php');
        exit;
    }

    // 2) Fetch the student’s username (to show in the topbar)
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($studentUsername);
    $stmt->fetch();
    $stmt->close();

    // 3) Determine which “page” is active for the student sidebar
    $currentPage = $GLOBALS['page'] ?? '';

    // 4) Output the shared <head> and open <body> + student sidebar + topbar
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8"/>
      <title><?= htmlspecialchars($pageTitle) ?> — Student Portal</title>
      <meta name="viewport" content="width=device-width, initial-scale=1" />

      <!-- Tailwind JIT + Bootstrap CSS (for convenience) -->
      <script src="https://cdn.tailwindcss.com"></script>
      <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet" />

      <!-- Bootstrap Icons (for sidebar icons) -->
      <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet" />

      <!-- Custom student CSS (if any) -->
      <link href="/student-portal/assets/custom.css" rel="stylesheet" />

      <style>
        a { text-decoration: none; }
      </style>
      <script>
        tailwind.config = {
          theme: {
            extend: {
              colors: {
                'student-primary':   '#1E40AF',  /* Indigo-700 */
                'student-secondary': '#60A5FA'   /* Blue-400 */
              }
            }
          }
        };
      </script>
    </head>

    <body class="bg-gray-100 min-h-screen flex">

      <!-- ─── Mobile Topbar with Hamburger ───────────────────────────────────────── -->
      <nav class="md:hidden bg-student-primary text-white p-3 flex items-center justify-between">
        <button id="studentSidebarToggle" class="text-white">
          <i class="bi bi-list text-2xl"></i>
        </button>
        <span class="text-lg font-semibold">Student Portal</span>
        <a href="/student-portal/logout.php" class="text-white">Logout
          <i class="bi bi-box-arrow-right text-xl">Logout</i>
        </a>
      </nav>

      <!-- ─── Student Sidebar ──────────────────────────────────────────────────── -->
      <aside id="studentSidebar"
             class="hidden md:block w-64 bg-student-secondary text-gray-900 p-4 transition-transform duration-200 ease-in-out">
        <ul class="space-y-2">
          <?php
          // Define the student menu here:
          $studentMenu = [
            'dashboard'    => ['bi-speedometer2',      'Dashboard',          'index.php'],
            'homework'     => ['bi-journal-text',      'My Homework',        'homework.php'],
            'attendance'   => ['bi-calendar-check',    'Attendance',         'attendance.php'],
            'progress'     => ['bi-bar-chart-line',    'My Progress',        'progress.php'],
            'stars'        => ['bi-star-fill',         'Stars & Rewards',    'stars.php'],
            'student_payment'     => ['bi-currency-rupee',    'Student Payment',           'student_payment.php'],
            'compensation'       => ['bi-journal-text','Compensation', 'compensation.php'],
            'profile'      => ['bi-person-circle',     'My Profile',         'profile.php'],
            'notifications'=> ['bi-bell-fill',         'Notifications',      'notifications.php'],
            'logout'=> ['bi-arrow-fill',         'Logout',      'logout.php'],
          ];

          foreach ($studentMenu as $key => [$iconClass, $labelText, $filename]) {
              $isActive = ($currentPage === $key);
              $linkClass = $isActive
                         ? 'bg-student-primary text-white'
                         : 'text-gray-800 hover:bg-student-primary hover:text-white';

              // If this is “logout”, we link directly to ../logout.php
              if ($key === 'logout') {
                  $href = $filename;
              } else {
                  // Otherwise, link into “?page=$key” under dashboard/student
                  $href = "/student-portal/dashboard/student/?page={$key}";
              }

              echo "<li>
                      <a href=\"{$href}\" class=\"flex items-center p-2 rounded-lg transition {$linkClass}\"
                         " . ($isActive ? 'aria-current=\"page\"' : '') . ">
                        <i class=\"bi {$iconClass} mr-3 text-lg\"></i>
                        <span class=\"font-medium\">{$labelText}</span>
                      </a>
                    </li>";
          }
          ?>
        </ul>
      </aside>

      <!-- ─── Main Container (Sidebar + Content) ───────────────────────────────── -->
      <div class="flex-1 flex flex-col">

        <!-- ─── Topbar (desktop) ───────────────────────────────────────────────── -->
        <header class="hidden md:flex bg-student-primary text-white">
          <div class="max-w-7xl mx-auto flex items-center justify-between px-6 py-3">
            <div class="flex items-center space-x-2 text-lg font-semibold">
              <i class="bi bi-shield-lock-fill"></i>
              <span>Student Portal</span>
            </div>
            <div class="flex items-center space-x-4">
              <span><?= htmlspecialchars($studentUsername) ?></span>
            </div>
          </div>
        </header>

        <!-- ─── Begin “page‐specific” content; every caller will wrap in <main>… </main> ─── -->
        <main class="flex-1 p-6 overflow-auto">
          <!-- Page content will be injected by the caller. -->
    <?php
}

////////////////////////////////////////////////////////////////////////////////
// 8) render_student_footer()
//    — Closes the <main>, prints a site‐wide footer, and closes </body></html>.
////////////////////////////////////////////////////////////////////////////////
function render_student_footer(): void
{
    ?>
        </main>

        <!-- ─── Site‐Wide Footer ─────────────────────────────────────────────────── -->
        <footer class="bg-student-secondary text-gray-900 text-center text-sm py-4">
          &copy; <?= date('Y') ?> Student Portal
        </footer>
      </div>  <!-- /.flex-1 flex flex-col -->
    </body>

    <!-- ─── Optional JS: Toggle Student Sidebar on Mobile ──────────────────────── -->
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('studentSidebarToggle');
        const sidebar = document.getElementById('studentSidebar');

        toggle.addEventListener('click', function() {
          if (sidebar.classList.contains('hidden')) {
            sidebar.classList.remove('hidden');
            sidebar.classList.add('block');
          } else {
            sidebar.classList.remove('block');
            sidebar.classList.add('hidden');
          }
        });
      });
    </script>
    </html>
    <?php
}
