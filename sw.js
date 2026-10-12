/**
 * ============================================================
 * VendoLink — Background Service Worker (sw.js)
 * ============================================================
 * Enables Progressive Web App (PWA) background push notifications,
 * native lockscreen alerts on Android/iOS/PC, and offline caching.
 */

const CACHE_NAME = 'vendolink-v2.5';
const ASSETS_TO_CACHE = [
  '/vendolink_claude_v1/manifest.json',
  '/vendolink_claude_v1/assets/css/app.css',
  '/vendolink_claude_v1/assets/js/app.js'
];

// 1. Service Worker Install
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE).catch(() => {});
    })
  );
  self.skipWaiting();
});

// 2. Service Worker Activate
self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// 3. Handle Background Push Events (Native lockscreen / background alert)
self.addEventListener('push', (event) => {
  let payload = {
    title: 'VendoLink System Alert',
    body: 'Hardware telemetry update received.',
    icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" rx="20" fill="%230eac5e"/><text y=".75em" x="15" font-size="70" fill="white">⚡</text></svg>',
    data: { url: '/vendolink_claude_v1/dashboard/alerts.php' }
  };

  if (event.data) {
    try {
      payload = Object.assign(payload, event.data.json());
    } catch (e) {
      payload.body = event.data.text();
    }
  }

  const options = {
    body: payload.body,
    icon: payload.icon,
    badge: payload.icon,
    vibrate: [200, 100, 200],
    data: payload.data || { url: '/vendolink_claude_v1/dashboard/alerts.php' },
    actions: [
      { action: 'open', title: 'Open Dashboard' },
      { action: 'close', title: 'Dismiss' }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(payload.title, options)
  );
});

// 4. Handle Notification Click (Open Dashboard when tapped from lockscreen)
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/vendolink_claude_v1/dashboard/alerts.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (let client of clientList) {
        if (client.url.includes('/vendolink_claude_v1/') && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
