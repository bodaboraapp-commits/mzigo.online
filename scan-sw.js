/* MziGO Scan PWA shell cache — API requests are always network-only. */
const CACHE = 'mzigo-scan-v22';
const SHELL = [
  '/scan.html',
  '/assets/css/scan.css?v=20261104a',
  '/assets/js/scan.js?v=20261104a',
  '/assets/js/jsQR.min.js?v=20261029h',
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

self.addEventListener('push', (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch (e) {
    payload = {};
  }
  const notification = payload.notification || {};
  const data = payload.data || payload || {};
  const title = notification.title || payload.title || 'MziGO.Online Scan';
  const body = notification.body || payload.body || 'You have a new scanner update.';
  const url = data.link || data.url || '/scan#recent';
  event.waitUntil(self.registration.showNotification(title, {
    body: body,
    icon: '/assets/img/scan-icon-192.png',
    badge: '/assets/img/scan-icon-192.png',
    tag: data.tracking ? 'mzigo-scan-' + data.tracking : 'mzigo-scan',
    data: { url: url }
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/scan#recent';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const client of windows) {
        if (client.url.indexOf('/scan') === 0) {
          return client.focus().then(() => client.navigate(target));
        }
      }
      return clients.openWindow(target);
    })
  );
});
