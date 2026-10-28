// Service Worker для Web Push уведомлений
// Версия: 1.0

self.addEventListener('push', function(event) {
    let data = {
        title: 'Новое уведомление',
        message: '',
        icon: '/wp-content/plugins/custom-service-calculator/assets/icon-192.png',
        badge: '/wp-content/plugins/custom-service-calculator/assets/badge-72.png',
        order_number: '',
        url: ''
    };
    
    if (event.data) {
        try {
            const parsed = event.data.json();
            data = Object.assign(data, parsed);
        } catch (e) {
            data.message = event.data.text();
        }
    }
    
    const options = {
        body: data.message,
        icon: data.icon,
        badge: data.badge,
        vibrate: [100, 50, 100],
        data: {
            order_number: data.order_number,
            url: data.url,
            type: data.type || 'info'
        },
        actions: [
            { action: 'open', title: 'Открыть' },
            { action: 'close', title: 'Закрыть' }
        ],
        tag: data.order_number || 'csc-notification',
        renotify: true,
        requireInteraction: false
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

// Клик по уведомлению
self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    const action = event.action;
    if (action === 'close') return;
    
    const url = event.notification.data?.url || self.location.origin;
    
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true })
            .then(function(clientList) {
                for (const client of clientList) {
                    if (client.url.includes(url) && 'focus' in client) {
                        return client.focus();
                    }
                }
                if (clients.openWindow) {
                    return clients.openWindow(url);
                }
            })
    );
});

// Установка Service Worker
self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});