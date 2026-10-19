// Service Worker para Mil Cópias - PWA e Notificações
const CACHE_NAME = 'milcopias-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Manipular cliques nas notificações
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  if (navigator.clearAppBadge) {
    navigator.clearAppBadge().catch(() => {});
  }
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow('./');
      }
    })
  );
});

// Manipular mensagens da aplicação principal (para atualizar Badge em segundo plano)
self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SET_BADGE') {
    const count = parseInt(event.data.count, 10) || 0;
    if (navigator.setAppBadge) {
      if (count > 0) {
        navigator.setAppBadge(count).catch(() => {});
      } else {
        navigator.clearAppBadge().catch(() => {});
      }
    }
  }
});
