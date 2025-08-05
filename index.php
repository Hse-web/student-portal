<!--Artovue/index.php-->
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome | Artovue</title>
  <link rel="manifest" href="/manifest.json">
  <script>
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/service-worker.js');
    }
  </script>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://unpkg.com/aos@2.3.4/dist/aos.css" rel="stylesheet">
  <script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
  <script>
      document.addEventListener('DOMContentLoaded', () => {
          AOS.init();
      });
  </script>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">

<!-- Navbar -->
<header class="bg-white shadow">
    <div class="max-w-7xl mx-auto px-4 py-4 flex justify-between items-center">
        <img src="assets/icons/icon-512.png" alt="Logo" class="h-20">
        <a href="login.php" class="text-white bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-md">Login</a>
    </div>
</header>

<!-- Hero Section -->
<section class="bg-gradient-to-r from-blue-500 to-cyan-500 text-white py-20 px-6 text-center" data-aos="fade-up">
    <div class="max-w-3xl mx-auto">
        <h2 class="text-4xl font-bold mb-4">Welcome to the Student Portal</h2>
        <p class="text-lg mb-6">Access your dashboard, track progress, submit assignments, and manage payments — all in one place.</p>
        <a href="login.php" class="bg-white text-blue-600 font-semibold px-6 py-3 rounded-full hover:bg-blue-100 transition">Login Now</a>
    </div>
</section>

<!-- Features Section -->
<section class="py-16 px-6 bg-white" data-aos="fade-up" data-aos-delay="200">
    <div class="max-w-6xl mx-auto grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-10 text-center">
        <div class="bg-blue-50 rounded-xl p-6 shadow-md" data-aos="zoom-in">
            <h3 class="text-xl font-semibold mb-2">📝 Submit Homework</h3>
            <p>Upload assignments and keep track of deadlines with ease.</p>
        </div>
        <div class="bg-blue-50 rounded-xl p-6 shadow-md" data-aos="zoom-in" data-aos-delay="100">
            <h3 class="text-xl font-semibold mb-2">📊 Track Progress</h3>
            <p>Monitor your art improvement with monthly progress updates.</p>
        </div>
        <div class="bg-blue-50 rounded-xl p-6 shadow-md" data-aos="zoom-in" data-aos-delay="200">
            <h3 class="text-xl font-semibold mb-2">💳 Easy Payments</h3>
            <p>Pay securely for your classes and view your transaction history.</p>
        </div>
    </div>
</section>

<!-- Optional Testimonials (Future Ready) -->
<!--
<section class="py-16 bg-gray-100">
    <div class="max-w-4xl mx-auto text-center" data-aos="fade-up">
        <h2 class="text-2xl font-bold mb-6">What our students say</h2>
        <p class="text-gray-700">"Best art experience ever!" - Aadyanth</p>
    </div>
</section>
-->

<footer class="text-center py-6 text-sm" style="color: #1E40AF;">
  © <?= date("Y") ?> <strong>Artovue</strong> — <span style="color:#60A5FA;">Powered by Rart Works</span>
</footer>

</body>
</html>
