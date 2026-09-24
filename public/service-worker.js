const STATIC_CACHE = 'meldeverkehr-static-v2';
const STATIC_ASSETS = [
  '/offline.html',
  '/assets/app.js',
  '/assets/icon.svg',
  '/manifest.webmanifest'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(STATIC_CACHE).then(cache => cache.addAll(STATIC_ASSETS)));
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(key => key !== STATIC_CACHE).map(key => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(() => caches.match('/offline.html'))
    );
    return;
  }

  if (url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest') {
    event.respondWith(
      caches.match(request).then(cached => cached || fetch(request).then(response => {
        const copy = response.clone();
        caches.open(STATIC_CACHE).then(cache => cache.put(request, copy));
        return response;
      }))
    );
  }
});


self.addEventListener('push', event => {
  event.waitUntil(
    fetch('/notifications/push-latest', {
      credentials: 'include',
      cache: 'no-store',
      headers: { 'Accept': 'application/json' }
    })
      .then(response => response.ok ? response.json() : null)
      .then(payload => {
        const item = payload?.data;
        if (!item?.title) return;

        return self.registration.showNotification(item.title, {
          body: item.body || '',
          icon: '/assets/icon.svg',
          badge: '/assets/icon.svg',
          tag: item.id ? 'notification-' + item.id : undefined,
          data: { url: item.action_url || '/notifications' }
        });
      })
      .catch(() => undefined)
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification?.data?.url || '/notifications';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clients => {
      for (const client of clients) {
        if ('focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return self.clients.openWindow ? self.clients.openWindow(url) : undefined;
    })
  );
});
