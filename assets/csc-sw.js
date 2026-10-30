// Service Worker для Web Push уведомлений
// Версия: 1.1
//
// ИСПРАВЛЕНО (Push-иконки): раньше использовались жёстко зашитые пути вида
// '/wp-content/plugins/custom-service-calculator/assets/...', которые ломались
// при нестандартном расположении plugins, на поддомене/мультисайте и при
// регистрации SW через корневой ?csc-sw=1. Теперь пути вычисляются
// автоматически из self.location (SW отдаётся плагином из assets/).

(function () {
    // Базовый URL папки assets/ плагина, например https://site.ru/wp-content/plugins/custom-service-calculator/assets/
    var ASSETS_BASE = new URL('./', self.location.href).href;

    self.CSC_ASSETS_BASE = ASSETS_BASE;
    self.CSC_DEFAULT_ICON = ASSETS_BASE + 'icon-192.png';
    self.CSC_DEFAULT_BADGE = ASSETS_BASE + 'badge-72.png';
})();

self.addEventListener('push', function(event) {
    let data = {
        title: 'Новое уведомление',
        message: '',
        icon: self.CSC_DEFAULT_ICON,
        badge: self.CSC_DEFAULT_BADGE,
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
    
    // Fallback: если сервер прислал пустой/битый путь к иконке или бейджу —
    // подставляем локальные файлы плагина.
    if (!data.icon)  data.icon  = self.CSC_DEFAULT_ICON;
    if (!data.badge) data.badge = self.CSC_DEFAULT_BADGE;

    const options = {
        body: data.body || data.message || '',
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