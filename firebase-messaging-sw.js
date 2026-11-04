/* MziGO.Online — Firebase Cloud Messaging service worker.
   Firebase web config is public. If the Firebase project changes, update this
   file (or deploy a generated replacement) together with Admin → Communications. */
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: 'AIzaSyCf-VjXL9pTY_hnHOUsd4ZX2vBLeW-_jVg',
  authDomain: 'komiuta.firebaseapp.com',
  projectId: 'komiuta',
  storageBucket: 'komiuta.firebasestorage.app',
  messagingSenderId: '99186909972',
  appId: '1:99186909972:web:c014173818634789b91801'
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function (payload) {
  const n = payload.notification || {};
  const d = payload.data || {};
  const link = d.link || '/';
  self.registration.showNotification(n.title || 'MziGO.Online', {
    body: n.body || '',
    icon: d.icon || '/assets/img/scan-icon-192.png',
    badge: '/assets/img/favicon-64.png',
    tag: d.tag || 'mzigo-update',
    data: { url: link }
  });
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
      for (const client of list) {
        if (client.url.indexOf(self.location.origin) === 0 && 'focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
