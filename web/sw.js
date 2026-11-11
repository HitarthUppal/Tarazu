/* Tarazu service worker: shows push notifications. Pushes carry no payload; the text is fetched from the server. */
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('push', e => {
  e.waitUntil((async () => {
    let msg = { title: 'Tarazu', body: 'You have an update.', url: './' };
    try {
      const sub = await self.registration.pushManager.getSubscription();
      const r = await fetch('api.php?op=inbox', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub && sub.endpoint }) });
      const j = await r.json(); if (j && j.title) msg = j;
    } catch (err) {}
    await self.registration.showNotification(msg.title, { body: msg.body, icon: 'icon-192.png', badge: 'icon-192.png', data: { url: msg.url || './' }, tag: 'tarazu-' + Date.now() });
  })());
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(ws => {
    const url = (e.notification.data && e.notification.data.url) || './';
    for (const w of ws) { if ('focus' in w) { if (url !== './' && 'navigate' in w) w.navigate(url).catch(() => {}); return w.focus(); } }
    return self.clients.openWindow(url);
  }));
});
