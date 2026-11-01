// Web push for Bomb Busters. Plain JS served from the site root so it can control every page.

self.addEventListener('push', (event) => {
    const data = event.data?.json();
    if (!data) return;

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const subscription = await self.registration.pushManager.getSubscription();
            // Apple revokes the subscription if a push shows nothing, so only others may stay quiet
            const mustShow = subscription?.endpoint.includes('apple.com');

            if (!mustShow && windows.some((client) => client.visibilityState === 'visible')) return;

            await self.registration.showNotification(data.title, {
                body: data.body,
                tag: data.tag,
                renotify: true,
                icon: '/icon-512.png',
                data: { url: data.url },
            });
        })(),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const url = event.notification.data.url;
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });

            if (!windows.length) return self.clients.openWindow(url);

            await windows[0].focus();
            await windows[0].navigate(url);
        })(),
    );
});
