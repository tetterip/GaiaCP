// GaiaCP admin: shows the push notifications (App\Push\WebPush) and opens their page when tapped.
const ICON = @json($icon);
const HOME = @json($home);

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let message = {};
    try {
        message = event.data ? event.data.json() : {};
    } catch (e) {
        message = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(self.registration.showNotification(message.title || 'GaiaCP', {
        body: message.body || '',
        icon: ICON,
        badge: ICON,
        tag: message.tag || undefined,
        data: { url: message.url || HOME },
    }));
});

// An open GaiaCP window goes to the page, else a new one opens there
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || HOME;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((client) => client.url.startsWith(HOME) && 'focus' in client);
        if (open) {
            return open.focus().then((client) => (client && 'navigate' in client ? client.navigate(url) : null));
        }

        return self.clients.openWindow(url);
    }));
});
