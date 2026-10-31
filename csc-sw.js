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
        // ИСПРАВЛЕНО (SW): renotify=true при существующем tag заставлял браузер
        // пере-показывать уже висящее уведомление (дубли/мигание). У каждого
        // заказа свой tag — новое уведомление о другом заказе покажется отдельно,
        // а повторное о том же заказе заменит старое. renotify не нужен.
        renotify: false,
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

    const rawUrl = (event.notification.data && event.notification.data.url) || self.location.origin;

    event.waitUntil((async function() {
        // ИСПРАВЛЕНО (SW): раньше использовалось client.url.includes(url) —
        // опасная подстрока: фокусировала любую вкладку, чей URL содержал
        // целевой (например, токен/параметры совпадали с частью другого адреса),
        // и не обновляла уже открытую страницу до нужной заявки.
        // Теперь: точное сравнение origin + pathname + search; при совпадении —
        // фокус и navigate к целевому URL; иначе — новое окно.
        let target = null;
        try { target = new URL(rawUrl, self.location.href); } catch (e) {}

        const allClients = await clients.matchAll({ type: 'window', includeUncontrolled: true });

        if (target) {
            for (const client of allClients) {
                let cUrl = null;
                try { cUrl = new URL(client.url); } catch (e) { continue; }
                if (cUrl.origin === target.origin &&
                    (cUrl.pathname + cUrl.search) === (target.pathname + target.search)) {
                    await client.focus();
                    if ('navigate' in client) {
                        try { await client.navigate(rawUrl); } catch (e) { /* уже там */ }
                    }
                    return;
                }
            }
        }

        const focusedClient = allClients.find(c => 'navigate' in c && c.focused);
        if (focusedClient) {
            // Есть активная вкладка сайта — открываем заявку в ней.
            try {
                await focusedClient.navigate(rawUrl);
                await focusedClient.focus();
                return;
            } catch (e) { /* fallthrough: openWindow */ }
        }

        // Фолбэк: если openWindow вернул null (лимит окон/мобильные браузеры)
        // или API недоступен — показываем резервное уведомление со ссылкой,
        // чтобы клик по пушу не становился «мёртвым».
        let opened = null;
        try { opened = clients.openWindow ? await clients.openWindow(rawUrl) : null; } catch (e) {}
        if (opened) return;

        await self.registration.showNotification('Откройте заявку', {
            body: rawUrl,
            tag: 'csc-open-fallback-' + Date.now(),
            data: { url: rawUrl },
            actions: [{ action: 'open', title: 'Перейти по ссылке' }]
        });
    })());
});

// Установка Service Worker
self.addEventListener('install', function(event) {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(clients.claim());
});