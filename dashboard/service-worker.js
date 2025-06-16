const cacheName = 'student-portal-v1';
const assetsToCache = [
  '/',
  '/login.php',
  '/dashboard/student/index.php',
  '/dashboard/admin/index.php',
  '/assets/css/style.css',
  '/assets/js/script.js'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(cacheName).then(cache => cache.addAll(assetsToCache))
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request).then(res => res || fetch(event.request))
  );
});
