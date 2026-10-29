/* Mzigo Scan PWA shell cache — API requests are always network-only. */
const CACHE = 'mzigo-scan-v10';
const SHELL = [
  '/scan.html',
  '/assets/css/scan.css?v=20261029d',
  '/assets/js/scan.js?v=20261029d',
  '/assets/js/jsQR.min.js?v=20261029d',
  '/assets/scan-manifest.webmanifest',
  '/assets/img/mzigo-logo.png',
  '/assets/img/scan-icon-192.png',
  '/assets/img/scan-icon-512.png'
];
self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;
  if (event.request.method !== 'GET') return;
  if (event.request.mode === 'navigate') {
    event.respondWith(fetch(event.request).catch(() => caches.match('/scan.html')));
    return;
  }
  event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
});
