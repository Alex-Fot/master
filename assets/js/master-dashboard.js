/**
 * Custom Service Calculator - Master Dashboard JS
 * Version: 6.2 (добавлена вкладка «Баланс» с финансовой сводкой и историей транзакций)
 * 
 * СОХРАНЕНО ИЗ 6.0:
 * - Карта зоны обслуживания (Leaflet) с перетаскиваемым маркером
 * - loadNotificationBadge() в polling (каждые 30 сек)
 * 
 * ВОССТАНОВЛЕНО ИЗ 5.1:
 * - credentials: 'omit' + query string в initActivityToggle
 * - handleNewNotification с звуком + тостом + browser notification
 * - knownNotifIds для детекции новых уведомлений
 * - handleNotificationClick
 * - stopPropagation на panel (предотвращение закрытия)
 * - loadNotifications() в Promise.all при init()
 * 
 * ДОБАВЛЕНО 6.2:
 * - Вкладка «Баланс»: финансовая сводка, периоды, детали, история транзакций
 * - Badge на вкладке с сокращённым балансом (12К, 1.5М)
 * - Автообновление баланса в polling при активной вкладке
 */
(function() {
    'use strict';

    // ============================================================
    // 1. CONFIG & STATE
    // ============================================================
    const CONFIG = window.CSC_MASTER_CONFIG || {};
    const STATE = {
        currentTab: 'waiting',
        orders: [],
        reviews: [],
        stats: {},
        balance: null,
        balanceLoaded: false,
        knownOrderNumbers: new Set(),
        isFirstLoad: true,
        soundEnabled: true,
        theme: 'light',
        notifications: [],
        knownNotifIds: new Set(),
        unreadNotifCount: 0,
        notifPanelOpen: false,
    };

    // ============================================================
    // 2. UTILS
    // ============================================================
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    function formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        const hour = String(d.getHours()).padStart(2, '0');
        const min = String(d.getMinutes()).padStart(2, '0');
        return `${day}.${month}.${year} ${hour}:${min}`;
    }

    function formatMoney(value) {
        const num = parseFloat(value) || 0;
        return num.toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽';
    }

    function formatMoneyShort(value) {
        const num = parseFloat(value) || 0;
        if (num >= 1000000) return (num / 1000000).toFixed(1) + 'М';
        if (num >= 1000) return (num / 1000).toFixed(0) + 'К';
        return num.toFixed(0);
    }

    function mapStatus(status) {
        const s = (status || '').toLowerCase();
        if (s === 'cancelled' || s === 'отменено') return 'cancelled';
        if (s === 'completed' || s === 'выполнено' || s === 'завершено') return 'completed';
        if (s === 'processing' || s === 'в работе') return 'active';
        if (s === 'pending' || s === 'searching' || s === 'awaiting_payment' || s === 'no_masters' || s === 'routing_timeout'
            || s === 'ожидает' || s === 'поиск мастера' || s === 'ожидает оплаты' || s === 'нет мастеров') return 'waiting';
        return 'waiting';
    }

    function getStatusLabel(status) {
        const labels = {
            'waiting': 'Ожидает',
            'active': 'В работе',
            'completed': 'Выполнено',
            'cancelled': 'Отменено',
        };
        return labels[mapStatus(status)] || status;
    }

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function formatPhone(phone) {
        const clean = String(phone || '').replace(/\D/g, '');
        if (clean.length === 11 && clean[0] === '7') {
            return '+7 (' + clean.substr(1, 3) + ') ' + clean.substr(4, 3) + '-' + clean.substr(7, 2) + '-' + clean.substr(9, 2);
        }
        return phone || '';
    }

    // ============================================================
    // 3. TOAST NOTIFICATIONS
    // ============================================================
    function showToast(type, title, message, duration = 4000) {
        const container = $('#csc-toast-container');
        if (!container) return;

        const icons = {
            success: '✅',
            error: '❌',
            info: 'ℹ️',
            warning: '⚠️',
        };

        const toast = document.createElement('div');
        toast.className = 'csc-toast csc-toast-' + type;
        toast.innerHTML = `
            <div class="csc-toast-icon">${icons[type] || 'ℹ️'}</div>
            <div class="csc-toast-content">
                <div class="csc-toast-title">${escapeHtml(title)}</div>
                ${message ? '<div class="csc-toast-message">' + escapeHtml(message) + '</div>' : ''}
            </div>
        `;

        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 250);
        }, duration);
    }

    // ============================================================
    // 4. THEME TOGGLE
    // ============================================================
    function initTheme() {
        const saved = localStorage.getItem('csc_theme') || 'light';
        STATE.theme = saved;
        applyTheme(saved);

        const btn = $('#csc-theme-toggle');
        if (btn) {
            btn.addEventListener('click', () => {
                const newTheme = STATE.theme === 'light' ? 'dark' : 'light';
                STATE.theme = newTheme;
                applyTheme(newTheme);
                localStorage.setItem('csc_theme', newTheme);
            });
        }
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        const icon = $('#csc-theme-icon');
        if (icon) icon.textContent = theme === 'dark' ? '☀️' : '🌙';
    }

    // ============================================================
    // 5. SOUND NOTIFICATIONS (Web Audio API)
    // ============================================================
    function initSoundToggle() {
        const saved = localStorage.getItem('csc_sound_enabled');
        STATE.soundEnabled = saved !== 'false';
        updateSoundButton();

        const btn = $('#csc-sound-toggle');
        if (btn) {
            btn.addEventListener('click', () => {
                STATE.soundEnabled = !STATE.soundEnabled;
                localStorage.setItem('csc_sound_enabled', STATE.soundEnabled ? 'true' : 'false');
                updateSoundButton();
                if (STATE.soundEnabled) playNotificationSound();
            });
        }
    }

    function updateSoundButton() {
        const btn = $('#csc-sound-toggle');
        if (!btn) return;
        btn.classList.toggle('enabled', STATE.soundEnabled);
        btn.classList.toggle('disabled', !STATE.soundEnabled);
        const icon = btn.querySelector('.csc-icon');
        if (icon) icon.textContent = STATE.soundEnabled ? '🔔' : '🔕';
    }

    function playNotificationSound() {
        if (!STATE.soundEnabled) return;
        
        try {
            // 1. Приоритет: воспроизведение кастомного MP3 из CONFIG
            if (CONFIG.soundUrl && CONFIG.soundUrl.trim() !== '') {
                const audio = new Audio(CONFIG.soundUrl);
                
                // 1.0 = 100% громкости (системный уровень для браузера)
                audio.volume = 1.0; 
                
                // play() возвращает Promise. 
                // Обрабатываем возможную блокировку автовоспроизведения браузером
                audio.play().catch(err => {
                    console.warn('CSC: Не удалось воспроизвести MP3 (возможно, требуется взаимодействие):', err);
                });
                return; // Прерываем выполнение, чтобы не включать Web Audio API
            }

            // 2. Фолбэк: генерация мелодии через Web Audio API (если MP3 не настроен)
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return;

            const ctx = new AudioCtx();
            const now = ctx.currentTime;

            const notes = [
                { freq: 880, start: 0, duration: 0.15 },
                { freq: 1175, start: 0.15, duration: 0.15 },
                { freq: 1318, start: 0.3, duration: 0.2 },
            ];

            notes.forEach(note => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.type = 'sine';
                osc.frequency.setValueAtTime(note.freq, now + note.start);
                gain.gain.setValueAtTime(0, now + note.start);
                gain.gain.linearRampToValueAtTime(0.2, now + note.start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.01, now + note.start + note.duration);
                osc.start(now + note.start);
                osc.stop(now + note.start + note.duration + 0.05);
            });

            setTimeout(() => ctx.close(), 1500);
        } catch (e) {
            console.warn('Sound error:', e);
        }
    }

    // ============================================================
    // 5.1 ОБРАБОТКА НОВОГО ЗАКАЗА (ИСПРАВЛЕНО: функция была не определена)
    // ============================================================
    function handleNewOrderDetected(orderNumber, orderData) {
        playNotificationSound();
    
        const clientName = orderData?.name || 'Клиент';
        const services = (orderData?.services_for_master || orderData?.services || []).slice(0, 2).join(', ');
    
        showToast(
            'info',
            '🆕 Новый заказ #' + orderNumber,
            'Клиент: ' + clientName + (services ? ' · ' + services : ''),
            6000
        );
    
        showBrowserNotification(
            '🆕 Новый заказ #' + orderNumber,
            'Клиент: ' + clientName + '. Проверьте вкладку «Ожидают».'
        );
    }
    
    // ============================================================
    // 5.2 БРАУЗЕРНЫЕ УВЕДОМЛЕНИЯ (ИСПРАВЛЕНО: функция была не определена)
    // ============================================================
    function showBrowserNotification(title, body) {
        if (!('Notification' in window)) return;
        if (Notification.permission === 'granted') {
            new Notification(title, {
                body: body,
                icon: '/favicon.ico',
                tag: 'csc-master-' + Date.now(),
            });
        } else if (Notification.permission !== 'denied') {
            Notification.requestPermission();
        }
    }

    // ============================================================
    // 6. API CALLS
    // ============================================================
    /**
     * Универсальный вызов REST API (WordPress REST)
     * Поддерживает GET (с токеном в заголовке) и POST (с токеном в FormData).
     */
    async function apiCall(endpoint, params = {}, method = 'POST') {
        const url = `${CONFIG.restUrl}${endpoint}`;
    
        if (method === 'GET') {
            // ИСПРАВЛЕНО: CONFIG.mid ставим после ...params, чтобы системный mid нельзя было переопределить извне
            const query = new URLSearchParams({
                ...params,
                mid: CONFIG.mid
            }).toString();
        
            // БЕЗОПАСНОСТЬ: токен передается в заголовке, а не в URL (не попадет в логи Nginx/Apache)
            const res = await fetch(`${url}?${query}`, {
                method: 'GET',
                headers: {
                    'X-CSC-Token': CONFIG.token
                }
            });
    
            // Обработка ошибок авторизации (401/403)
            if (res.status === 401 || res.status === 403) {
                console.error('[CSC] Auth failed (HTTP ' + res.status + '). Token may be invalid.');
                if (typeof showToast === 'function') {
                    showToast('error', 'Ошибка авторизации', 'Токен доступа недействителен. Обновите страницу.');
                }
                throw new Error('Auth failed: HTTP ' + res.status);
            }
        
            if (!res.ok) {
                const text = await res.text().catch(() => '');
                console.error('[CSC] API error HTTP ' + res.status + ':', text);
                throw new Error('HTTP ' + res.status);
            }
            return await res.json();
        }
    
        // POST запрос
        const fd = new FormData();
        fd.append('mid', CONFIG.mid);
        fd.append('token', CONFIG.token);
        Object.entries(params).forEach(([k, v]) => {
            fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
        });
    
        const res = await fetch(url, { method: 'POST', body: fd });
    
        // Обработка ошибок авторизации (401/403)
        if (res.status === 401 || res.status === 403) {
            console.error('[CSC] Auth failed (HTTP ' + res.status + '). Token may be invalid.');
            if (typeof showToast === 'function') {
                showToast('error', 'Ошибка авторизации', 'Токен доступа недействителен. Обновите страницу.');
            }
            throw new Error('Auth failed: HTTP ' + res.status);
        }
    
        if (!res.ok) {
            const text = await res.text().catch(() => '');
            console.error('[CSC] API error HTTP ' + res.status + ':', text);
            throw new Error('HTTP ' + res.status);
        }
        
        return await res.json();
    }
    
    /**
     * Вызов стандартного WordPress AJAX (admin-ajax.php)
     * Используется для действий, требующих nonce (например, push_subscribe, mark_read).
     */
    async function ajaxCall(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        if (CONFIG.nonce) fd.append('_wpnonce', CONFIG.nonce);
        
        Object.keys(data).forEach(key => {
            const val = data[key];
            fd.append(key, typeof val === 'object' ? JSON.stringify(val) : val);
        });
    
        const response = await fetch(CONFIG.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
    
        if (!response.ok) {
            console.error('[CSC] AJAX error HTTP ' + response.status);
            throw new Error('HTTP ' + response.status);
        }
        
        return response.json();
    }

    // ============================================================
    // 7. LOAD ORDERS
    // ============================================================
    async function loadOrders() {
        try {
            const data = await apiCall('orders');
            if (!data.success) {
                showToast('error', 'Ошибка', data.message || 'Не удалось загрузить заказы');
                return;
            }

            const newOrders = data.data.orders || [];
            const newNumbers = new Set(newOrders.map(o => o.order_number));

            // Детекция новых заказов для звука и браузерных уведомлений
            if (!STATE.isFirstLoad && STATE.knownOrderNumbers.size > 0) {
                newNumbers.forEach(num => {
                    if (!STATE.knownOrderNumbers.has(num)) {
                        const orderData = newOrders.find(o => o.order_number === num);
                        if (typeof handleNewOrderDetected === 'function') {
                            handleNewOrderDetected(num, orderData);
                        }
                    }
                });
            }

            STATE.knownOrderNumbers = newNumbers;
            STATE.orders = newOrders;
            STATE.isFirstLoad = false;

            renderOrders();
            updateStats();
            updateBadges();

            // Помечаем уведомления о заказах как прочитанные
            ajaxCall('csc_mark_read', { type: 'master', id: CONFIG.mid }).catch(() => {});
        } catch (e) {
            console.error('Load orders error:', e);
            if (!STATE.isFirstLoad) {
                showToast('error', 'Ошибка сети', 'Проверьте подключение');
            }
        }
    }

    // ============================================================
    // 8. LOAD REVIEWS
    // ============================================================
    async function loadReviews() {
        try {
            const data = await apiCall('reviews');
            if (!data.success) {
                showToast('error', 'Ошибка', data.message || 'Не удалось загрузить отзывы');
                return;
            }

            STATE.reviews = data.data.reviews || [];
            STATE.avgRating = data.data.avg_rating || 0;

            // обновляем рейтинг ВСЕГДА (даже если вкладка неактивна)
            updateRatingStat();
            updateBadges();

            // Рендер списка отзывов — только если активна вкладка reviews
            if (STATE.currentTab === 'reviews') {
                renderReviews();
            }
        } catch (e) {
            console.error('Load reviews error:', e);
            showToast('error', 'Ошибка сети', 'Не удалось загрузить отзывы');
        }
    }

    // ============================================================
    // 9. LOAD STATS
    // ============================================================
    async function loadStats() {
        try {
            const data = await apiCall('stats');
            if (data.success) {
                STATE.stats = data.data.stats || {};
                updateStats();
            }
        } catch (e) {
            console.warn('Stats load error:', e);
        }
    }

    // ============================================================
    // BALANCE: ЗАГРУЗКА ДАННЫХ
    // ============================================================
    async function loadBalance() {
        const section = $('#csc-balance-section');
        if (section) section.removeAttribute('hidden');

        try {
            const data = await apiCall('balance');
            if (data.success && data.data) {
                STATE.balance = data.data;
                STATE.balanceLoaded = true;
                renderBalance();
                updateBalanceBadge();
            } else {
                showToast('error', 'Ошибка', data.message || 'Не удалось загрузить баланс');
            }
        } catch (e) {
            console.error('Load balance error:', e);
            showToast('error', 'Ошибка сети', 'Проверьте подключение');
        }
    }

    function updateBalanceBadge() {
        const badge = $('#csc-badge-balance');
        if (!badge || !STATE.balance) return;
        const bal = STATE.balance.balance || 0;
        if (bal > 0) {
            badge.textContent = formatMoneyShort(bal);
            badge.removeAttribute('hidden');
        } else {
            badge.setAttribute('hidden', '');
        }
    }

    // ============================================================
    // BALANCE: РЕНДЕРИНГ
    // ============================================================
    function renderBalance() {
        const section = $('#csc-balance-section');
        if (!section || !STATE.balance) return;
        section.removeAttribute('hidden');

        const b = STATE.balance;

        // --- Сводка: текущий баланс ---
        const summaryEl = $('#csc-balance-summary');
        if (summaryEl) {
            summaryEl.innerHTML = `
                <div class="csc-balance-main-card">
                    <div class="csc-balance-main-label">Текущий баланс</div>
                    <div class="csc-balance-main-value">${formatMoney(b.balance)}</div>
                    <div class="csc-balance-main-sub">Доступно к выплате</div>
                    <button type="button" class="csc-balance-refresh-btn" id="csc-balance-refresh" title="Обновить">
                        🔄 Обновить
                    </button>
                </div>
            `;
            const refreshBtn = summaryEl.querySelector('#csc-balance-refresh');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', async function() {
                    this.disabled = true;
                    this.innerHTML = '<span class="csc-spinner"></span>';
                    STATE.balanceLoaded = false;
                    await loadBalance();
                    this.disabled = false;
                    this.innerHTML = '🔄 Обновить';
                });
            }
        }

        // --- Периоды ---
        const periodsEl = $('#csc-balance-periods');
        if (periodsEl) {
            periodsEl.innerHTML = `
                <div class="csc-balance-period-card">
                    <div class="csc-balance-period-icon">📅</div>
                    <div class="csc-balance-period-label">Сегодня</div>
                    <div class="csc-balance-period-value">${formatMoney(b.today_earned)}</div>
                </div>
                <div class="csc-balance-period-card">
                    <div class="csc-balance-period-icon">📆</div>
                    <div class="csc-balance-period-label">За 7 дней</div>
                    <div class="csc-balance-period-value">${formatMoney(b.week_earned)}</div>
                </div>
                <div class="csc-balance-period-card">
                    <div class="csc-balance-period-icon">🗓️</div>
                    <div class="csc-balance-period-label">За месяц</div>
                    <div class="csc-balance-period-value">${formatMoney(b.month_earned)}</div>
                </div>
                <div class="csc-balance-period-card">
                    <div class="csc-balance-period-icon">⏳</div>
                    <div class="csc-balance-period-label">В работе</div>
                    <div class="csc-balance-period-value">${formatMoney(b.pending_amount)}</div>
                </div>
            `;
        }

        // --- Детали ---
        const detailsEl = $('#csc-balance-details');
        if (detailsEl) {
            const commLabel = b.comm_type === 'percent'
                ? b.comm_val + '%'
                : formatMoney(b.comm_val) + ' / заказ';

            detailsEl.innerHTML = `
                <div class="csc-balance-detail-card">
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">✅ Выполнено заказов</span>
                        <span class="csc-balance-detail-value">${b.completed_count}</span>
                    </div>
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">🔧 В работе</span>
                        <span class="csc-balance-detail-value">${b.active_count}</span>
                    </div>
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">❌ Отменено</span>
                        <span class="csc-balance-detail-value">${b.cancelled_count}</span>
                    </div>
                    <div class="csc-balance-detail-row csc-balance-detail-divider"></div>
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">💰 Заработано всего</span>
                        <span class="csc-balance-detail-value csc-text-success">${formatMoney(b.total_earned)}</span>
                    </div>
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">🏷️ Комиссия (${commLabel})</span>
                        <span class="csc-balance-detail-value csc-text-muted">−${formatMoney(b.total_commission)}</span>
                    </div>
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">💸 Выплачено</span>
                        <span class="csc-balance-detail-value csc-text-muted">−${formatMoney(b.total_payout)}</span>
                    </div>
                    ${b.penalties_amount > 0 ? `
                    <div class="csc-balance-detail-row">
                        <span class="csc-balance-detail-label">⚠️ Штрафы (${b.penalties_total} шт.)</span>
                        <span class="csc-balance-detail-value csc-text-danger">−${formatMoney(b.penalties_amount)}</span>
                    </div>
                    ` : ''}
                </div>
            `;
        }

        // --- История транзакций ---
        const historyEl = $('#csc-balance-history');
        if (historyEl) {
            const txns = b.transactions || [];
            if (txns.length === 0) {
                historyEl.innerHTML = `
                    <div class="csc-balance-history-header">📜 История операций</div>
                    <div class="csc-balance-history-empty">
                        <div class="csc-balance-history-empty-icon">📭</div>
                        <p>Операций пока нет</p>
                        <p class="csc-balance-history-empty-sub">Завершённые заказы появятся здесь</p>
                    </div>
                `;
            } else {
                let rowsHtml = txns.map(tx => {
                    const amountClass = tx.amount >= 0 ? 'csc-text-success' : 'csc-text-danger';
                    const amountSign = tx.amount >= 0 ? '+' : '';
                    const dateStr = tx.date ? formatDate(tx.date) : '—';
                    const metaStr = tx.total > 0
                        ? `Заказ: ${formatMoney(tx.total)}` + (tx.commission > 0 ? ` · Комиссия: ${formatMoney(tx.commission)}` : '')
                        : '';

                    return `
                        <div class="csc-balance-txn-row">
                            <div class="csc-balance-txn-icon">${tx.icon || '💰'}</div>
                            <div class="csc-balance-txn-info">
                                <div class="csc-balance-txn-label">${escapeHtml(tx.label)}</div>
                                <div class="csc-balance-txn-meta">${dateStr}${metaStr ? ' · ' + metaStr : ''}</div>
                            </div>
                            <div class="csc-balance-txn-amount ${amountClass}">
                                ${amountSign}${formatMoney(tx.amount)}
                            </div>
                        </div>
                    `;
                }).join('');

                historyEl.innerHTML = `
                    <div class="csc-balance-history-header">
                        📜 История операций
                        <span class="csc-balance-history-count">${txns.length} записей</span>
                    </div>
                    <div class="csc-balance-txn-list">${rowsHtml}</div>
                `;
            }
        }
    }

    // ============================================================
    // 10. RENDER ORDERS
    // ============================================================
    function renderOrders() {
        const list = $('#csc-orders-list');
        const empty = $('#csc-empty');
        const loader = $('#csc-loader');

        if (loader) loader.setAttribute('hidden', '');

        if (STATE.currentTab === 'reviews' || STATE.currentTab === 'balance') {
            if (list) list.setAttribute('hidden', '');
            return;
        }

        const filtered = STATE.orders.filter(o => mapStatus(o.status) === STATE.currentTab);

        if (filtered.length === 0) {
            if (list) list.setAttribute('hidden', '');
            if (empty) {
                empty.removeAttribute('hidden');
                const emptyIcon = $('#csc-empty-icon');
                const emptyTitle = $('#csc-empty-title');
                const emptyText = $('#csc-empty-text');

                const emptyStates = {
                    waiting: { icon: '⏳', title: 'Нет ожидающих заказов', text: 'Новые заказы появятся здесь' },
                    active: { icon: '🔧', title: 'Нет заказов в работе', text: 'Примите заказ из вкладки «Ожидают»' },
                    completed: { icon: '✅', title: 'Нет выполненных заказов', text: 'Завершённые заказы появятся здесь' },
                    cancelled: { icon: '❌', title: 'Нет отменённых заказов', text: 'Всё хорошо!' },
                };

                const es = emptyStates[STATE.currentTab] || emptyStates.waiting;
                if (emptyIcon) emptyIcon.textContent = es.icon;
                if (emptyTitle) emptyTitle.textContent = es.title;
                if (emptyText) emptyText.textContent = es.text;
            }
            return;
        }

        if (empty) empty.setAttribute('hidden', '');
        if (list) {
            list.removeAttribute('hidden');
            list.innerHTML = filtered.map(renderOrderCard).join('');

            list.querySelectorAll('.csc-order-card').forEach(card => {
                card.addEventListener('click', () => {
                    const num = card.dataset.orderNumber;
                    const order = STATE.orders.find(o => o.order_number === num);
                    if (order) openOrderModal(order);
                });
            });
        }
    }

    function renderOrderCard(order) {
        const status = mapStatus(order.status);
        const services = order.services_for_master || order.services || [];
        const servicesPreview = Array.isArray(services) ? services.slice(0, 2).join(', ') : '';
        const photos = (order.files?.photos || []).length;
        const videos = (order.files?.video || []).length;
        const hasFiles = photos + videos > 0;

        return `
            <article class="csc-order-card" data-order-number="${escapeHtml(order.order_number)}">
                <div class="csc-order-header">
                    <span class="csc-order-number">#${escapeHtml(order.order_number)}</span>
                    <span class="csc-order-status csc-status-${status}">${getStatusLabel(order.status)}</span>
                </div>
                <div class="csc-order-details">
                    <div class="csc-order-row">
                        <span class="csc-order-row-icon">👤</span>
                        <span class="csc-order-row-text"><strong>${escapeHtml(order.name || 'Клиент')}</strong></span>
                    </div>
                    <div class="csc-order-row">
                        <span class="csc-order-row-icon">📍</span>
                        <span class="csc-order-row-text">${escapeHtml(order.address || 'Адрес не указан')}</span>
                    </div>
                    <div class="csc-order-row">
                        <span class="csc-order-row-icon">📅</span>
                        <span class="csc-order-row-text">${escapeHtml(order.date || '')} в ${escapeHtml(order.time || '')}</span>
                    </div>
                    ${servicesPreview ? `
                    <div class="csc-order-row">
                        <span class="csc-order-row-icon">🔧</span>
                        <span class="csc-order-row-text">${escapeHtml(servicesPreview)}</span>
                    </div>
                    ` : ''}
                </div>
                <div class="csc-order-amount">
                    <span class="csc-order-amount-label">Сумма</span>
                    <span>${formatMoney(order.total)}</span>
                </div>
                ${hasFiles ? `
                <div class="csc-order-files">
                    ${photos > 0 ? '<span>📷 ' + photos + '</span>' : ''}
                    ${videos > 0 ? '<span>🎥 ' + videos + '</span>' : ''}
                </div>
                ` : ''}
            </article>
        `;
    }

    // ============================================================
    // 11. RENDER REVIEWS (С ПОДДЕРЖКОЙ ФОТО И LIGHTBOX)
    // ============================================================
    function renderReviews() {
        const list = $('#csc-reviews-list');
        const empty = $('#csc-empty');
        const orders = $('#csc-orders-list');

        if (orders) orders.setAttribute('hidden', '');

        if (STATE.reviews.length === 0) {
            if (list) list.setAttribute('hidden', '');
            if (empty) {
                empty.removeAttribute('hidden');
                const emptyIcon = $('#csc-empty-icon');
                const emptyTitle = $('#csc-empty-title');
                const emptyText = $('#csc-empty-text');
                if (emptyIcon) emptyIcon.textContent = '⭐';
                if (emptyTitle) emptyTitle.textContent = 'Пока нет отзывов';
                if (emptyText) emptyText.textContent = 'Отзывы клиентов появятся здесь после выполнения заказов';
            }
            return;
        }

        if (empty) empty.setAttribute('hidden', '');
        if (!list) return;
        list.removeAttribute('hidden');

        // Сохраняем массив globally для лайтбокса
        window.CSC_DASH_REVIEWS = STATE.reviews;

        const summaryHtml = `
            <div class="csc-reviews-summary">
                <div class="csc-reviews-summary-rating">${STATE.avgRating.toFixed(1)}</div>
                <div class="csc-reviews-summary-stars">${renderStars(STATE.avgRating)}</div>
                <div class="csc-reviews-summary-count">${STATE.reviews.length} ${pluralize(STATE.reviews.length, ['отзыв', 'отзыва', 'отзывов'])}</div>
            </div>
        `;

        // Передаем индекс rIdx для корректной работы лайтбокса
        const reviewsHtml = STATE.reviews.map((review, rIdx) => renderReviewCard(review, rIdx)).join('');
        list.innerHTML = summaryHtml + reviewsHtml;
    }

    function renderReviewCard(review, rIdx) {
        const authorName = review.customer || 'Клиент';
        const authorLetter = authorName.charAt(0).toUpperCase();
    
        let photosHtml = '';
        const photos = Array.isArray(review.photos) ? review.photos : [];
        if (photos.length > 0) {
            photosHtml = '<div class="csc-review-photos" style="margin-top: 12px;">';
            photosHtml += '<div class="csc-review-photos-label" style="font-size: 12px; color: var(--csc-text-tertiary); margin-bottom: 8px; font-weight: 600;">📷 Фото от клиента (' + photos.length + ')</div>';
            photosHtml += '<div class="csc-review-photos-grid" style="display: flex; flex-wrap: wrap; gap: 8px;">';
            photos.forEach(function(photo, pIdx) {
                const url = photo.url || photo;
                photosHtml += `<img src="${escapeHtml(url)}" alt="Фото клиента" loading="lazy" class="csc-review-photo-img" style="width: 80px; height: 80px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 1px solid var(--csc-border); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" onclick="window.CSC_Lightbox.open(window.CSC_DASH_REVIEWS[${rIdx}].photos.map(x => x.url || x), ${pIdx})" onerror="this.style.display='none'">`;
            });
            photosHtml += '</div></div>';
        }
    
        return `
            <article class="csc-review-card">
                <div class="csc-review-header">
                    <div class="csc-review-author">
                        <div class="csc-review-avatar">${escapeHtml(authorLetter)}</div>
                        <div class="csc-review-author-info">
                            <div class="csc-review-author-name">${escapeHtml(authorName)}</div>
                            <div class="csc-review-date">${formatDate(review.date)}</div>
                        </div>
                    </div>
                    <div class="csc-review-rating">${renderStars(review.rating)}</div>
                </div>
                ${review.comment ? `<div class="csc-review-text">"${escapeHtml(review.comment)}"</div>` : '<div class="csc-review-text" style="color: var(--csc-text-tertiary); font-style: normal;">Клиент не оставил комментарий</div>'}
                ${photosHtml}
                <div class="csc-review-order-link">
                    <span>Заказ #${escapeHtml(review.order_number)}</span>
                    <span><strong>${formatMoney(review.total)}</strong></span>
                </div>
            </article>
        `;
    }

    function renderStars(rating) {
        const full = Math.floor(rating);
        const half = rating - full >= 0.5;
        let html = '';
        
        for (let i = 1; i <= 5; i++) {
            if (i <= full) {
                html += '<span class="csc-star-filled">★</span>';
            } else if (i === full + 1 && half) {
                html += '<span class="csc-star-half">★</span>';
            } else {
                html += '<span class="csc-star-empty">★</span>';
            }
        }
        return html;
    }

    function pluralize(n, forms) {
        n = Math.abs(n) % 100;
        const n1 = n % 10;
        if (n > 10 && n < 20) return forms[2];
        if (n1 > 1 && n1 < 5) return forms[1];
        if (n1 === 1) return forms[0];
        return forms[2];
    }

    // ============================================================
    // LIGHTBOX ДЛЯ ФОТОГРАФИЙ
    // ============================================================
    window.CSC_Lightbox = {
        overlay: null,
        urls: [],
        currentIndex: 0,
        init: function() {
            if (this.overlay) return;
            this.overlay = document.createElement('div');
            this.overlay.id = 'csc-lightbox-overlay';
            this.overlay.style.cssText = 'display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.9); backdrop-filter:blur(8px); z-index:10000; justify-content:center; align-items:center; flex-direction:column;';
            this.overlay.innerHTML = `
                <button id="csc-lightbox-close" style="position:absolute; top:20px; right:20px; background:rgba(255,255,255,0.1); border:none; color:white; font-size:24px; width:40px; height:40px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center;">&times;</button>
                <button id="csc-lightbox-prev" style="position:absolute; left:20px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.1); border:none; color:white; font-size:24px; width:48px; height:48px; border-radius:50%; cursor:pointer;">&#10094;</button>
                <img id="csc-lightbox-img" src="" style="max-width:90vw; max-height:85vh; object-fit:contain; border-radius:12px; box-shadow:0 20px 50px rgba(0,0,0,0.5);">
                <button id="csc-lightbox-next" style="position:absolute; right:20px; top:50%; transform:translateY(-50%); background:rgba(255,255,255,0.1); border:none; color:white; font-size:24px; width:48px; height:48px; border-radius:50%; cursor:pointer;">&#10095;</button>
            `;
            document.body.appendChild(this.overlay);

            document.getElementById('csc-lightbox-close').addEventListener('click', () => this.close());
            this.overlay.addEventListener('click', (e) => { if (e.target === this.overlay) this.close(); });
            document.getElementById('csc-lightbox-prev').addEventListener('click', () => this.navigate(-1));
            document.getElementById('csc-lightbox-next').addEventListener('click', () => this.navigate(1));
            
            document.addEventListener('keydown', (e) => {
                if (this.overlay.style.display === 'flex') {
                    if (e.key === 'Escape') this.close();
                    if (e.key === 'ArrowLeft') this.navigate(-1);
                    if (e.key === 'ArrowRight') this.navigate(1);
                }
            });
        },
        open: function(urls, index) {
            this.init();
            this.urls = urls;
            this.currentIndex = index || 0;
            this.updateImage();
            this.overlay.style.display = 'flex';
        },
        close: function() {
            if (this.overlay) this.overlay.style.display = 'none';
        },
        navigate: function(dir) {
            this.currentIndex += dir;
            if (this.currentIndex < 0) this.currentIndex = this.urls.length - 1;
            if (this.currentIndex >= this.urls.length) this.currentIndex = 0;
            this.updateImage();
        },
        updateImage: function() {
            document.getElementById('csc-lightbox-img').src = this.urls[this.currentIndex];
        }
    };

    // ============================================================
    // 13. UPDATE STATS & BADGES
    // ============================================================
    function updateStats() {
        const counts = {
            waiting: 0,
            active: 0,
            completed: 0,
            cancelled: 0,
        };

        STATE.orders.forEach(o => {
            const status = mapStatus(o.status);
            if (counts[status] !== undefined) counts[status]++;
        });

        const pending = $('#csc-stat-pending');
        const active = $('#csc-stat-active');
        const done = $('#csc-stat-done');

        if (pending) pending.textContent = counts.waiting;
        if (active) active.textContent = counts.active;
        if (done) done.textContent = counts.completed;

        const statusDot = $('#csc-status-dot');
        const statusText = $('#csc-status-text');
        if (statusDot && statusText) {
            if (counts.active > 0) {
                statusDot.style.background = 'var(--csc-warning)';
                statusDot.style.boxShadow = '0 0 0 2px var(--csc-warning)';
                statusText.textContent = 'В работе: ' + counts.active;
            } else if (counts.waiting > 0) {
                statusDot.style.background = 'var(--csc-accent)';
                statusDot.style.boxShadow = '0 0 0 2px var(--csc-accent)';
                statusText.textContent = 'Ожидают: ' + counts.waiting;
            } else {
                statusDot.style.background = 'var(--csc-success)';
                statusDot.style.boxShadow = '0 0 0 2px var(--csc-success)';
                statusText.textContent = 'Готов к работе';
            }
        }

        updateRatingStat();
    }

    function updateRatingStat() {
        const rating = $('#csc-stat-rating');
        if (rating) {
            const avgRating = STATE.avgRating || STATE.stats.avg_rating || 0;
            rating.textContent = avgRating > 0 ? avgRating.toFixed(1) + ' ⭐' : '—';
        }
    }

    function updateBadges() {
        const counts = { waiting: 0, active: 0, completed: 0, cancelled: 0 };
        STATE.orders.forEach(o => {
            const status = mapStatus(o.status);
            if (counts[status] !== undefined) counts[status]++;
        });

        Object.keys(counts).forEach(key => {
            const badge = $('#csc-badge-' + key);
            if (badge) {
                if (counts[key] > 0) {
                    badge.textContent = counts[key];
                    badge.removeAttribute('hidden');
                } else {
                    badge.setAttribute('hidden', '');
                }
            }
        });

        const reviewsBadge = $('#csc-badge-reviews');
        if (reviewsBadge) {
            if (STATE.reviews.length > 0) {
                reviewsBadge.textContent = STATE.reviews.length;
                reviewsBadge.removeAttribute('hidden');
            } else {
                reviewsBadge.setAttribute('hidden', '');
            }
        }

        updateBalanceBadge();
    }

    // ============================================================
    // 14. ORDER DETAIL MODAL
    // ============================================================
    function openOrderModal(order) {
        const modal = $('#csc-order-modal');
        const body = $('#csc-modal-body');
        const footer = $('#csc-modal-footer');
        const numEl = $('#csc-modal-order-number');

        if (!modal || !body || !footer || !numEl) return;

        numEl.textContent = order.order_number;

        const services = order.services_for_master || order.services || [];
        const photos = order.files?.photos || [];
        const videos = order.files?.video || [];
        const phone = order.phone || '';
        const phoneFormatted = formatPhone(phone);

        body.innerHTML = `
            <div class="csc-modal-row">
                <span class="csc-modal-row-label">Клиент</span>
                <span class="csc-modal-row-value">
                    ${escapeHtml(order.name || 'Без имени')}
                    ${phone ? '<br><a href="tel:' + escapeHtml(phone) + '" class="csc-phone-link">' + escapeHtml(phoneFormatted) + '</a>' : ''}
                </span>
            </div>
            <div class="csc-modal-row">
                <span class="csc-modal-row-label">Email</span>
                <span class="csc-modal-row-value">${order.email ? escapeHtml(order.email) : '—'}</span>
            </div>
            <div class="csc-modal-row">
                <span class="csc-modal-row-label">Адрес</span>
                <span class="csc-modal-row-value">
                    ${order.address ? '<a href="https://yandex.ru/maps/?text=' + encodeURIComponent(order.address) + '" target="_blank" class="csc-address-link">' + escapeHtml(order.address) + ' 🗺️</a>' : '—'}
                </span>
            </div>
            <div class="csc-modal-row">
                <span class="csc-modal-row-label">Дата и время</span>
                <span class="csc-modal-row-value">${escapeHtml(order.date || '')} в ${escapeHtml(order.time || '')}</span>
            </div>
            <div class="csc-modal-row">
                <span class="csc-modal-row-label">Статус</span>
                <span class="csc-modal-row-value">
                    <span class="csc-order-status csc-status-${mapStatus(order.status)}">${getStatusLabel(order.status)}</span>
                </span>
            </div>
            <div class="csc-modal-row">
                <span class="csc-modal-row-label">Сумма</span>
                <span class="csc-modal-row-value" style="font-size: 18px; color: var(--csc-accent);">${formatMoney(order.total)}</span>
            </div>

            ${services.length > 0 ? `
                <div class="csc-modal-section-title">Услуги</div>
                <div class="csc-modal-services-list">
                    ${services.map(s => '<div class="csc-modal-service">' + escapeHtml(s) + '</div>').join('')}
                </div>
            ` : ''}

            ${order.comment ? `
                <div class="csc-modal-section-title">Комментарий клиента</div>
                <div class="csc-review-text" style="margin: 0;">"${escapeHtml(order.comment)}"</div>
            ` : ''}

            ${photos.length + videos.length > 0 ? `
                <div class="csc-modal-section-title">Файлы (${photos.length + videos.length})</div>
                <div class="csc-modal-files">
                    ${photos.map(p => '<a href="' + escapeHtml(p) + '" target="_blank" class="csc-modal-file"><img src="' + escapeHtml(p) + '" alt="Фото"></a>').join('')}
                    ${videos.map(v => '<a href="' + escapeHtml(v) + '" target="_blank" class="csc-modal-file">🎥</a>').join('')}
                </div>
            ` : ''}

            ${(order.completion_files && order.completion_files.length > 0) ? `
                <div class="csc-modal-section-title">📸 Отчёт о выполнении (${order.completion_files.length})</div>
                <div class="csc-modal-files">
                    ${order.completion_files.map(f =>
                        f.type === 'image'
                            ? '<a href="' + escapeHtml(f.url) + '" target="_blank" class="csc-modal-file"><img src="' + escapeHtml(f.url) + '" alt="Отчёт"></a>'
                            : '<a href="' + escapeHtml(f.url) + '" target="_blank" class="csc-modal-file">🎥</a>'
                    ).join('')}
                </div>
            ` : ''}
        `;

        const status = mapStatus(order.status);
        let footerHtml = `
            <button type="button" class="csc-btn csc-btn-secondary" onclick="document.getElementById('csc-order-modal').setAttribute('hidden', '')">Закрыть</button>
        `;

        if (status === 'waiting') {
            footerHtml += `
                <button type="button" class="csc-btn csc-btn-success" data-action="accept" data-order="${escapeHtml(order.order_number)}">
                    ✓ Принять заказ
                </button>
            `;
        } else if (status === 'active') {
            footerHtml += `
                <button type="button" class="csc-btn csc-btn-success" data-action="complete-report" data-order="${escapeHtml(order.order_number)}">
                   📸 Выполнено с отчётом
                </button>
            `;
        }

        footer.innerHTML = footerHtml;

        footer.querySelectorAll('button[data-action]').forEach(btn => {
            btn.addEventListener('click', () => {
                const action = btn.dataset.action;
                const orderNum = btn.dataset.order;
                if (action === 'complete-report') {
                    window.cscOpenCompletionReport(orderNum);
                } else {
                    handleMasterAction(action, orderNum, btn);
                }
            });
        });

        modal.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        const modal = $('#csc-order-modal');
        if (modal) {
            modal.setAttribute('hidden', '');
            document.body.style.overflow = '';
        }
    }

    async function handleMasterAction(action, orderNumber, btn) {
        if (!action || !orderNumber) return;

        const labels = {
            accept: 'Принять заказ',
            complete: 'Отметить выполненным',
        };

        if (!confirm((labels[action] || 'Подтвердите действие') + ' #' + orderNumber + '?')) return;

        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '<span class="csc-loader-spinner" style="width:16px;height:16px;border-width:2px;"></span>';

        try {
            const data = await apiCall('action', { action: action, order_number: orderNumber });
            if (data.success) {
                showToast('success', 'Готово', 'Заказ #' + orderNumber + ' обновлён');
                closeModal();
                await loadOrders();
            } else {
                showToast('error', 'Ошибка', data.message || 'Не удалось выполнить действие');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        } catch (e) {
            showToast('error', 'Ошибка сети', 'Проверьте подключение');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }

    // ============================================================
    // 15. TAB SWITCHING (Обновлено для поддержки Баланса)
    // ============================================================
    function initTabs() {
        $$('.csc-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                $$('.csc-tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                STATE.currentTab = btn.dataset.tab;

                if (STATE.currentTab === 'reviews') {
                    $('#csc-orders-list')?.setAttribute('hidden', '');
                    $('#csc-empty')?.setAttribute('hidden', '');
                    $('#csc-balance-section')?.setAttribute('hidden', '');
                    if (STATE.reviews.length === 0) {
                        loadReviews();
                    } else {
                        renderReviews();
                    }
                } else if (STATE.currentTab === 'balance') {
                    $('#csc-orders-list')?.setAttribute('hidden', '');
                    $('#csc-empty')?.setAttribute('hidden', '');
                    $('#csc-reviews-list')?.setAttribute('hidden', '');
                    if (!STATE.balanceLoaded) {
                        loadBalance();
                    } else {
                        renderBalance();
                    }
                } else {
                    $('#csc-balance-section')?.setAttribute('hidden', '');
                    $('#csc-reviews-list')?.setAttribute('hidden', '');
                    renderOrders();
                }
            });
        });
    }

    // ============================================================
    // 16. MODAL EVENTS
    // ============================================================
    function initModalEvents() {
        const closeBtn = $('#csc-modal-close');
        const backdrop = document.querySelector('.csc-modal-backdrop');
        const modal = $('#csc-order-modal');

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (backdrop) backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal && !modal.hasAttribute('hidden')) {
                closeModal();
            }
        });
    }

    // ============================================================
    // 17. WEB PUSH SUBSCRIPTION
    // ИСПРАВЛЕНО: явная регистрация SW из корня сайта (/csc-sw.js)
    // с защитой от повторной регистрации и корректной обработкой ошибок
    // ============================================================
    function urlBase64ToUint8Array(base64String) {
        if (!base64String) return null;
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        try {
            const rawData = window.atob(base64);
            const outputArray = new Uint8Array(rawData.length);
            for (let i = 0; i < rawData.length; ++i) {
                outputArray[i] = rawData.charCodeAt(i);
            }
            return outputArray;
        } catch (e) {
            return null;
        }
    }

    /**
     * Получение или регистрация Service Worker.
     * Сначала пытается найти уже зарегистрированный SW.
     * Если не найден — регистрирует новый из /csc-sw.js с scope '/'.
     */
    async function getOrRegisterServiceWorker() {
        // 1. Проверяем существующую регистрацию
        let reg = await navigator.serviceWorker.getRegistration('/');
        if (reg) {
            // Убеждаемся что SW активен
            if (reg.active) return reg;
            // Если SW в процессе установки — ждём активации
            await navigator.serviceWorker.ready;
            return navigator.serviceWorker.getRegistration('/');
        }

        // 2. Регистрируем новый Service Worker
        try {
            reg = await navigator.serviceWorker.register('/csc-sw.js', {
                scope: '/'
            });
            // Ждём активации
            await navigator.serviceWorker.ready;
            return reg;
        } catch (err) {
            // 404 — файл SW не существует на сервере (плагин не активирован или активация не прошла)
            if (err.message && err.message.includes('404')) {
                console.warn('[CSC] Service Worker file not found at /csc-sw.js. Push disabled.');
            } else {
                console.warn('[CSC] Service Worker registration failed:', err);
            }
            return null;
        }
    }

    function initPushSubscription() {
        // Проверка поддержки браузером
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return;
        }

        // Проверка VAPID ключа (передаётся из PHP через wp_localize_script)
        if (!CONFIG.vapidKey) {
            return;
        }

        const vapidArray = urlBase64ToUint8Array(CONFIG.vapidKey);
        if (!vapidArray) {
            console.warn('[CSC] Invalid VAPID key format');
            return;
        }

        // Флаг защиты от повторной инициализации в течение сессии
        if (window.__cscPushInit) return;
        window.__cscPushInit = true;

        // 1. Запрашиваем разрешение на уведомления
        Notification.requestPermission().then(permission => {
            if (permission !== 'granted') {
                return;
            }

            // 2. Получаем или регистрируем Service Worker
            getOrRegisterServiceWorker().then(reg => {
                if (!reg) return;

                // 3. Проверяем существующую подписку
                return reg.pushManager.getSubscription().then(existingSub => {
                    // Если подписка уже есть и валидна — отправляем её на сервер заново
                    if (existingSub && !existingSub.expirationTime) {
                        return sendSubscriptionToServer(existingSub);
                    }

                    // Если подписки нет или она истекла — создаём новую
                    return reg.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: vapidArray,
                    }).then(sub => {
                        return sendSubscriptionToServer(sub);
                    });
                });
            }).catch(err => {
                console.warn('[CSC] Push subscription flow error:', err);
            });
        }).catch(err => {
            console.warn('[CSC] Notification permission error:', err);
        });
    }

    /**
     * Отправка подписки на сервер через AJAX.
     * Отдельная функция чтобы избежать дублирования кода.
     */
    function sendSubscriptionToServer(subscription) {
        if (!subscription) return Promise.resolve();

        return ajaxCall('csc_push_subscribe', {
            user_type: 'master',
            user_id: CONFIG.mid,
            subscription: JSON.stringify(subscription),
        }).then(res => {
            if (res && res.success) {
                console.log('[CSC] Push subscription saved to server');
            }
        }).catch(err => {
            console.warn('[CSC] Failed to save push subscription:', err);
        });
    }
    // ============================================================
    // 18. POLLING (подгрузка бейджа уведомлений + баланс)
    // ============================================================
    function startPolling() {
        setInterval(async () => {
            if (STATE.currentTab !== 'reviews') {
                await loadOrders();
            }
            loadNotificationBadge();
            // Обновление баланса в реальном времени (если вкладка активна)
            if (STATE.currentTab === 'balance') {
                await loadBalance();
            }
        }, 30000);
    }

    // ============================================================
    // 19. ОТЧЁТ О ВЫПОЛНЕНИИ (COMPLETION REPORT)
    // ============================================================
    const COMPLETION_STATE = {
        orderNumber: '',
        files: [],
        maxFiles: 10,
    };

    window.cscOpenCompletionReport = function(orderNumber) {
        COMPLETION_STATE.orderNumber = orderNumber;
        const order = STATE.orders.find(o => o.order_number === orderNumber);
        COMPLETION_STATE.files = order?.completion_files || [];

        const modal = document.createElement('div');
        modal.className = 'csc-modal';
        modal.id = 'csc-completion-modal';
        modal.innerHTML = `
            <div class="csc-modal-backdrop"></div>
            <div class="csc-modal-content csc-completion-modal-content">
                <div class="csc-modal-header">
                    <h2 class="csc-modal-title">📸 Отчёт о выполнении #${escapeHtml(orderNumber)}</h2>
                    <button type="button" class="csc-modal-close" id="csc-completion-close">✕</button>
                </div>
                <div class="csc-modal-body">
                    <div class="csc-completion-info">
                        <p>Прикрепите <strong>до 10 фото</strong> или <strong>1 видео (до 100 МБ)</strong> после выполнения работ.</p>
                        <p class="csc-completion-hint">Это обязательно для подтверждения выполнения заказа.</p>
                    </div>

                    <div class="csc-dropzone" id="csc-dropzone">
                        <div class="csc-dropzone-content">
                            <div class="csc-dropzone-icon">📷</div>
                            <div class="csc-dropzone-text">Перетащите файлы сюда</div>
                            <div class="csc-dropzone-hint">или нажмите для выбора</div>
                            <div class="csc-dropzone-limits">Фото до 10 МБ · Видео до 100 МБ · Макс 10 файлов</div>
                        </div>
                        <input type="file" id="csc-file-input" multiple accept="image/*,video/*" style="display:none;">
                    </div>

                    <div class="csc-progress-bar-wrapper" id="csc-progress-wrapper" hidden>
                        <div class="csc-progress-bar">
                            <div class="csc-progress-bar-fill" id="csc-progress-fill"></div>
                        </div>
                        <div class="csc-progress-text" id="csc-progress-text">Загрузка...</div>
                    </div>

                    <div class="csc-files-preview" id="csc-files-preview"></div>

                    <div class="csc-completion-error" id="csc-completion-error" hidden></div>
                </div>
                <div class="csc-modal-footer">
                    <button type="button" class="csc-btn csc-btn-secondary" id="csc-completion-cancel">Отмена</button>
                    <button type="button" class="csc-btn csc-btn-success" id="csc-completion-confirm" disabled>
                        ✓ Подтвердить выполнение
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        document.body.style.overflow = 'hidden';

        initCompletionEvents();
        renderCompletionFiles();
        updateConfirmButton();
    };

    function initCompletionEvents() {
        const modal = $('#csc-completion-modal');
        const dropzone = $('#csc-dropzone');
        const fileInput = $('#csc-file-input');
        const closeBtn = $('#csc-completion-close');
        const cancelBtn = $('#csc-completion-cancel');
        const confirmBtn = $('#csc-completion-confirm');

        closeBtn?.addEventListener('click', closeCompletionModal);
        cancelBtn?.addEventListener('click', closeCompletionModal);
        modal.querySelector('.csc-modal-backdrop')?.addEventListener('click', closeCompletionModal);

        dropzone?.addEventListener('click', () => fileInput?.click());
        fileInput?.addEventListener('change', (e) => handleFiles(Array.from(e.target.files)));

        dropzone?.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone?.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone?.addEventListener('drop', (e) => {
            e.preventDefault();
            dropzone.classList.remove('dragover');
            handleFiles(Array.from(e.dataTransfer.files));
        });

        confirmBtn?.addEventListener('click', confirmCompletion);
    }

    function closeCompletionModal() {
        const modal = $('#csc-completion-modal');
        if (modal) modal.remove();
        document.body.style.overflow = '';
    }

    async function handleFiles(fileList) {
        const errorEl = $('#csc-completion-error');
        if (errorEl) errorEl.setAttribute('hidden', '');

        const currentCount = COMPLETION_STATE.files.length;
        const available = COMPLETION_STATE.maxFiles - currentCount;

        if (available <= 0) {
            showCompletionError('Достигнут лимит в ' + COMPLETION_STATE.maxFiles + ' файлов');
            return;
        }

        const filesToUpload = fileList.slice(0, available);
        if (fileList.length > available) {
            showCompletionError(`Можно добавить ещё только ${available} файл(ов). Остальные пропущены.`);
        }

        const validFiles = [];
        for (const file of filesToUpload) {
            const ext = file.name.split('.').pop().toLowerCase();
            const isImage = ['jpg', 'jpeg', 'png', 'heic', 'webp'].includes(ext);
            const isVideo = ['mp4', 'mov', 'avi', 'webm'].includes(ext);

            if (!isImage && !isVideo) {
                showCompletionError(`Файл "${file.name}" имеет недопустимый формат`);
                continue;
            }
            if (isImage && file.size > 10 * 1024 * 1024) {
                showCompletionError(`Фото "${file.name}" больше 10 МБ`);
                continue;
            }
            if (isVideo && file.size > 100 * 1024 * 1024) {
                showCompletionError(`Видео "${file.name}" больше 100 МБ`);
                continue;
            }
            validFiles.push(file);
        }

        if (validFiles.length === 0) return;

        const fd = new FormData();
        fd.append('mid', CONFIG.mid);
        fd.append('token', CONFIG.token);
        fd.append('order_number', COMPLETION_STATE.orderNumber);
        fd.append('action', 'upload');
        validFiles.forEach(f => fd.append('completion_files[]', f));

        showProgress(true);

        try {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', CONFIG.restUrl + 'upload-completion');

            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const pct = Math.round((e.loaded / e.total) * 100);
                    updateProgress(pct);
                }
            });

            const response = await new Promise((resolve, reject) => {
                xhr.onload = () => {
                    try {
                        resolve(JSON.parse(xhr.responseText));
                    } catch (e) {
                        reject(new Error('Parse error'));
                    }
                };
                xhr.onerror = () => reject(new Error('Network error'));
                xhr.send(fd);
            });

            showProgress(false);

            if (response.success) {
                COMPLETION_STATE.files = COMPLETION_STATE.files.concat(response.data.files);
                renderCompletionFiles();
                updateConfirmButton();

                const order = STATE.orders.find(o => o.order_number === COMPLETION_STATE.orderNumber);
                if (order) order.completion_files = COMPLETION_STATE.files;

                showToast('success', 'Загружено', response.data.files.length + ' файл(ов)');
            } else {
                showCompletionError(response.message || 'Ошибка загрузки');
            }
        } catch (e) {
            showProgress(false);
            showCompletionError('Ошибка сети. Проверьте подключение.');
        }
    }

    function showProgress(show) {
        const wrapper = $('#csc-progress-wrapper');
        if (wrapper) {
            if (show) wrapper.removeAttribute('hidden');
            else wrapper.setAttribute('hidden', '');
        }
    }

    function updateProgress(percent) {
        const fill = $('#csc-progress-fill');
        const text = $('#csc-progress-text');
        if (fill) fill.style.width = percent + '%';
        if (text) text.textContent = 'Загрузка... ' + percent + '%';
    }

    function showCompletionError(msg) {
        const el = $('#csc-completion-error');
        if (el) {
            el.textContent = msg;
            el.removeAttribute('hidden');
        }
    }

    function renderCompletionFiles() {
        const preview = $('#csc-files-preview');
        if (!preview) return;

        if (COMPLETION_STATE.files.length === 0) {
            preview.innerHTML = '<div class="csc-files-empty">Файлы не загружены</div>';
            return;
        }

        preview.innerHTML = COMPLETION_STATE.files.map((file, idx) => `
            <div class="csc-file-preview-item">
                ${file.type === 'image'
                    ? `<img src="${escapeHtml(file.url)}" alt="Фото" class="csc-file-thumb">`
                    : `<div class="csc-file-thumb csc-file-video-thumb">🎥</div>`
                }
                <button type="button" class="csc-file-remove" data-index="${idx}" title="Удалить">✕</button>
                <div class="csc-file-meta">
                    <div class="csc-file-type">${file.type === 'image' ? 'Фото' : 'Видео'}</div>
                    <div class="csc-file-size">${formatFileSize(file.size)}</div>
                </div>
            </div>
        `).join('');

        preview.querySelectorAll('.csc-file-remove').forEach(btn => {
            btn.addEventListener('click', () => removeCompletionFile(parseInt(btn.dataset.index)));
        });
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' Б';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' КБ';
        return (bytes / (1024 * 1024)).toFixed(1) + ' МБ';
    }

    async function removeCompletionFile(index) {
        if (!confirm('Удалить этот файл из отчёта?')) return;

        try {
            const data = await apiCall('upload-completion', {
                action: 'delete_file',
                order_number: COMPLETION_STATE.orderNumber,
                file_index: index,
            });

            if (data.success) {
                COMPLETION_STATE.files.splice(index, 1);
                renderCompletionFiles();
                updateConfirmButton();

                const order = STATE.orders.find(o => o.order_number === COMPLETION_STATE.orderNumber);
                if (order) order.completion_files = COMPLETION_STATE.files;
            } else {
                showCompletionError(data.message || 'Ошибка удаления');
            }
        } catch (e) {
            showCompletionError('Ошибка сети');
        }
    }

    function updateConfirmButton() {
        const btn = $('#csc-completion-confirm');
        if (!btn) return;

        const hasFiles = COMPLETION_STATE.files.length > 0;
        btn.disabled = !hasFiles;

        if (hasFiles) {
            btn.innerHTML = `✓ Подтвердить выполнение (${COMPLETION_STATE.files.length})`;
        } else {
            btn.innerHTML = '✓ Подтвердить выполнение';
        }
    }

    async function confirmCompletion() {
        if (COMPLETION_STATE.files.length === 0) {
            showCompletionError('Прикрепите хотя бы один файл');
            return;
        }

        if (!confirm('Подтвердить выполнение заказа #' + COMPLETION_STATE.orderNumber + '? После этого статус изменится на "Выполнено".')) return;

        const btn = $('#csc-completion-confirm');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="csc-loader-spinner" style="width:16px;height:16px;border-width:2px;"></span>';
        }

        try {
            const data = await apiCall('upload-completion', {
                action: 'complete_confirm',
                order_number: COMPLETION_STATE.orderNumber,
            });

            if (data.success) {
                showToast('success', 'Заказ выполнен', '#' + COMPLETION_STATE.orderNumber + ' отмечен как выполненный');
                closeCompletionModal();
                const orderModal = $('#csc-order-modal');
                if (orderModal) orderModal.setAttribute('hidden', '');
                document.body.style.overflow = '';
                await loadOrders();
            } else {
                showCompletionError(data.message || 'Ошибка подтверждения');
                if (btn) {
                    btn.disabled = false;
                    updateConfirmButton();
                }
            }
        } catch (e) {
            showCompletionError('Ошибка сети');
            if (btn) {
                btn.disabled = false;
                updateConfirmButton();
            }
        }
    }

    // ============================================================
    // 20. ПЕРЕКЛЮЧАТЕЛЬ АКТИВНОСТИ (credentials)
    // ============================================================
    function initActivityToggle() {
        const toggleBtn = $('#csc-toggle-activity');
        if (!toggleBtn) return;

        toggleBtn.addEventListener('click', async function() {
            if (this.classList.contains('loading')) return;
            const isOnline = this.classList.contains('active');
            const actionText = isOnline ? 'деактивировать' : 'активировать';

            if (!confirm(`Вы уверены что хотите ${actionText} статус?` + (isOnline ? '\nВы перестанете получать новые заказы.' : '\nВы начнёте получать новые заказы.'))) {
                return;
            }

            this.classList.add('loading');
            const originalHtml = this.innerHTML;
            this.innerHTML = '<span class="csc-spinner"></span>';

            try {
                const url = CONFIG.restUrl + 'toggle-activity'
                    + '?mid=' + encodeURIComponent(CONFIG.mid)
                    + '&token=' + encodeURIComponent(CONFIG.token);

                const response = await fetch(url, {
                    method: 'POST',
                    credentials: 'omit',
                });

                let data;
                const contentType = response.headers.get('content-type') || '';

                if (contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    throw new Error('Сервер вернул не-JSON (HTTP ' + response.status + ')');
                }

                if (!response.ok) {
                    throw new Error(data.message || ('HTTP ' + response.status));
                }

                if (data.success) {
                    const newStatus = data.data.is_online;
                    this.classList.toggle('active', newStatus);
                    const label = this.querySelector('.csc-toggle-label');
                    if (label) label.textContent = newStatus ? 'Активен' : 'Неактивен';
                    const statusText = $('#csc-status-text');
                    if (statusText) {
                        statusText.innerHTML = newStatus ? '🟢 Активен - принимаете заказы' : '⚪ Неактивен';
                    }
                    const statusDot = $('#csc-status-dot');
                    if (statusDot) {
                        statusDot.style.background = newStatus ? 'var(--csc-success)' : 'var(--csc-text-tertiary)';
                    }
                    showToast('success', newStatus ? 'Вы активны' : 'Вы деактивированы', data.message);
                    if (newStatus) {
                        await loadOrders();
                    }
                } else {
                    showToast('error', 'Ошибка', data.message || 'Не удалось изменить статус');
                }
            } catch (e) {
                console.error('[CSC] Toggle activity error:', e);
                showToast('error', 'Ошибка', e.message || 'Проверьте подключение');
            } finally {
                this.classList.remove('loading');
                this.innerHTML = originalHtml;
            }
        });
    }

    // ============================================================
    // 21. КАРТА ЗОНЫ ОБСЛУЖИВАНИЯ
    // Перетаскиваемый маркер точки выезда + круг радиуса 1-3 км
    // ============================================================
    let serviceMap = null;
    let serviceMarker = null;
    let serviceCircle = null;
    let serviceRadiusValue = parseInt(CONFIG.serviceRadius, 10) || 10;

    function initServiceZoneMap() {
        const mapEl = $('#csc-service-map');
        if (!mapEl) return;

        if (typeof L === 'undefined') {
            console.error('[CSC] Leaflet не загружен. Проверьте подключение в шапке.');
            return;
        }

        // Центр карты: сохранённая точка мастера или дефолт (Москва)
        const hasSavedPoint = CONFIG.serviceLat && CONFIG.serviceLng && (parseFloat(CONFIG.serviceLat) !== 0 || parseFloat(CONFIG.serviceLng) !== 0);
        const center = hasSavedPoint
            ? [parseFloat(CONFIG.serviceLat), parseFloat(CONFIG.serviceLng)]
            : [55.7558, 37.6173];

        serviceMap = L.map('csc-service-map', {
            center: center,
            zoom: 12,
            scrollWheelZoom: true,
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 19,
        }).addTo(serviceMap);

        // Перетаскиваемый маркер точки выезда
        serviceMarker = L.marker(center, {
            draggable: true,
            title: 'Точка выезда',
        }).addTo(serviceMap);

        // Круг радиуса обслуживания
        serviceCircle = L.circle(center, {
            radius: serviceRadiusValue * 1000,
            color: '#6366f1',
            weight: 2,
            fillColor: '#6366f1',
            fillOpacity: 0.12,
        }).addTo(serviceMap);

        // При перетаскивании маркера обновляем центр круга
        serviceMarker.on('dragend', function() {
            const pos = serviceMarker.getLatLng();
            serviceCircle.setLatLng(pos);
        });

        // Клик по карте — перемещаем маркер и круг
        serviceMap.on('click', function(e) {
            serviceMarker.setLatLng(e.latlng);
            serviceCircle.setLatLng(e.latlng);
        });

        // Ползунок радиуса
        const radiusSlider = $('#csc-service-radius');
        const radiusLabel = $('#csc-radius-value');
        if (radiusSlider) {
            radiusSlider.value = serviceRadiusValue;
            if (radiusLabel) radiusLabel.textContent = serviceRadiusValue;

            radiusSlider.addEventListener('input', function() {
                serviceRadiusValue = parseInt(this.value, 10);
                if (radiusLabel) radiusLabel.textContent = serviceRadiusValue;
                if (serviceCircle) serviceCircle.setRadius(serviceRadiusValue * 1000);
            });
        }

        // Кнопка сохранения зоны
        const saveBtn = $('#csc-save-zone-btn');
        if (saveBtn) {
            saveBtn.addEventListener('click', saveServiceZone);
        }

        // Пересчёт размера карты (важно при отложенной отрисовке)
        setTimeout(() => serviceMap.invalidateSize(), 300);
    }

    async function saveServiceZone() {
        if (!serviceMarker) return;

        const pos = serviceMarker.getLatLng();
        const saveBtn = $('#csc-save-zone-btn');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="csc-spinner"></span> Сохранение...';
        }

        try {
            const fd = new FormData();
            fd.append('mid', CONFIG.mid);
            fd.append('token', CONFIG.token);
            fd.append('lat', pos.lat.toFixed(7));
            fd.append('lng', pos.lng.toFixed(7));
            fd.append('radius', serviceRadiusValue);
            if (CONFIG.serviceAddress) {
                fd.append('address', CONFIG.serviceAddress);
            }

            const response = await fetch(CONFIG.restUrl + 'update-location', {
                method: 'POST',
                body: fd,
            });

            const data = await response.json();

            if (data.success) {
                showToast('success', 'Сохранено', 'Зона обслуживания обновлена');
            } else {
                showToast('error', 'Ошибка', data.message || 'Не удалось сохранить зону');
            }
        } catch (e) {
            showToast('error', 'Ошибка сети', 'Проверьте подключение');
        } finally {
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '💾 Сохранить зону';
            }
        }
    }

    // ============================================================
    // 22. ВИДЖЕТ УВЕДОМЛЕНИЙ
    // Полный функционал: детекция новых, звук, тост, клик, стоп-пропагейшн
    // ОБЪЕДИНЕНО: лучшая версия (защита от 404 + очистка + открытие заказа)
    // ============================================================

    /**
     * Загрузка списка уведомлений мастера через REST endpoint.
     * ИСПРАВЛЕНО: при 404/401/500 прекращаем повторные попытки
     * и не спамим консоль.
     */
    let notificationsEndpointBroken = false;

    async function loadNotifications() {
        // Если эндпоинт недоступен — не повторяем запросы
        if (notificationsEndpointBroken) return;

        try {
            const data = await apiCall('notifications', {}, 'GET');
            if (!data.success) return;

            const notifications = data.data.notifications || [];
            const unreadCount = data.data.unread_count || 0;

            // Обнаружение новых уведомлений (для звука и тоста)
            if (!STATE.isFirstLoad && STATE.knownNotifIds.size > 0) {
                notifications.forEach(n => {
                    const nid = n.id || n.created_at;
                    if (!STATE.knownNotifIds.has(nid) && !n.read) {
                        handleNewNotification(n);
                    }
                });
            }

            STATE.knownNotifIds = new Set(notifications.map(n => n.id || n.created_at));
            STATE.notifications = notifications;
            STATE.unreadNotifCount = unreadCount;

            updateNotifBadge();

            if (STATE.notifPanelOpen) {
                renderNotifications();
            }
        } catch (e) {
            // ИСПРАВЛЕНО: при 404/401/500 отключаем повторные запросы
            const statusMatch = (e.message || '').match(/HTTP (\d{3})/);
            if (statusMatch) {
                const code = parseInt(statusMatch[1], 10);
                if (code === 404 || code === 401 || code === 500) {
                    notificationsEndpointBroken = true;
                    console.warn('[CSC] Notifications endpoint unavailable (HTTP ' + code + '). Polling stopped.');
                    return;
                }
            }
            console.warn('Load notifications error:', e);
        }
    }

    /**
     * Обработка нового (только что пришедшего) уведомления.
     * Звук + тост + browser notification.
     */
    function handleNewNotification(n) {
        playNotificationSound();
        showToast('info', n.title || 'Уведомление', n.message || '', 5000);
        showBrowserNotification(n.title || 'Уведомление', n.message || '');
    }

    /**
     * Обновление бейджа на колокольчике.
     * Если непрочитанных = 0, бейдж скрывается.
     */
    function updateNotifBadge() {
        const badge = $('#csc-notif-badge');
        if (!badge) return;

        if (STATE.unreadNotifCount > 0) {
            badge.textContent = STATE.unreadNotifCount > 99 ? '99+' : STATE.unreadNotifCount;
            badge.removeAttribute('hidden');
        } else {
            badge.setAttribute('hidden', '');
        }
    }

    /**
     * Рендеринг списка уведомлений в панели.
     * ОБЪЕДИНЕНО: добавлен клик по уведомлению для открытия заказа
     */
    function renderNotifications() {
        const list = $('#csc-notif-list');
        if (!list) return;

        if (STATE.notifications.length === 0) {
            list.innerHTML = `
                <div class="csc-notif-empty">
                    <div class="csc-notif-empty-icon">🔔</div>
                    <p class="csc-notif-empty-text">Уведомлений пока нет</p>
                </div>
            `;
            return;
        }

        // Иконки по типу уведомления
        const typeIcons = {
            approve: '✅',
            reject: '❌',
            block: '🚫',
            unblock: '🔓',
            reset_penalties: '🔄',
            new_order: '🆕',
            status_change: '📦',
            default: '🔔'
        };

        list.innerHTML = STATE.notifications.map(n => {
            const icon = typeIcons[n.type] || typeIcons.default;
            const unreadClass = n.read ? '' : ' unread';
            const nid = escapeHtml(n.id || n.created_at);
            const orderNum = escapeHtml(n.order_number || n.order || '');
            const timeStr = n.created_at ? formatDate(n.created_at) : (n.time_ago || '');
            const orderBadge = orderNum
                ? `<span style="margin-left:6px; padding:2px 8px; background:var(--csc-accent-light, rgba(59,130,246,0.1)); color:var(--csc-accent, #3b82f6); border-radius:10px; font-size:11px; font-weight:600;">#${orderNum}</span>`
                : '';

            return `
                <div class="csc-notif-item${unreadClass}" data-notif-id="${nid}" data-order="${orderNum}" style="cursor:${orderNum ? 'pointer' : 'default'};">
                    <div class="csc-notif-icon">${icon}</div>
                    <div class="csc-notif-body">
                        <div class="csc-notif-title">
                            ${escapeHtml(n.title || 'Уведомление')}
                            ${orderBadge}
                        </div>
                        <div class="csc-notif-message">${escapeHtml(n.message || '')}</div>
                        ${timeStr ? `<div class="csc-notif-time">${timeStr}</div>` : ''}
                    </div>
                    ${!n.read ? '<div class="csc-notif-unread-dot"></div>' : ''}
                </div>
            `;
        }).join('');

        // Обработчики клика по каждому уведомлению
        list.querySelectorAll('.csc-notif-item').forEach(item => {
            item.addEventListener('click', () => {
                handleNotificationClick(item.dataset.notifId, item.dataset.order);
            });
        });
    }

    /**
     * Клик по уведомлению.
     * ОБЪЕДИНЕНО: закрываем панель + помечаем как прочитанные + открываем заказ
     */
    function handleNotificationClick(notifId, orderNumber) {
        // Закрываем панель
        closeNotificationsPanel();
        // Помечаем все как прочитанные (сервер обновит)
        markAllNotificationsRead();

        // Если в уведомлении есть номер заказа — открываем модалку заказа
        if (orderNumber && orderNumber.trim() !== '') {
            const order = STATE.orders.find(o => o.order_number === orderNumber);
            if (order) {
                // Небольшая задержка чтобы панель успела закрыться
                setTimeout(() => openOrderModal(order), 200);
            } else {
                // Заказ не в текущем списке — подгружаем и открываем
                showToast('info', 'Загрузка заказа', '#' + orderNumber, 2000);
                loadOrders().then(() => {
                    const freshOrder = STATE.orders.find(o => o.order_number === orderNumber);
                    if (freshOrder) openOrderModal(freshOrder);
                });
            }
        }
    }

    /**
     * Отправка запроса на отметку всех уведомлений как прочитанных.
     * Endpoint: POST /csc/v1/master/notifications/mark-read
     */
    async function markAllNotificationsRead() {
        if (STATE.unreadNotifCount === 0) return;

        try {
            const data = await apiCall('notifications/mark-read');
            if (data.success) {
                // Локально помечаем все как read
                STATE.notifications.forEach(n => { n.read = true; });
                STATE.unreadNotifCount = 0;
                updateNotifBadge();
                if (STATE.notifPanelOpen) {
                    renderNotifications();
                }
            }
        } catch (e) {
            console.warn('Mark read error:', e);
        }
    }

    /**
     * НОВОЕ: Очистить все уведомления (удалить из истории).
     * Endpoint: POST /csc/v1/master/notifications/clear
     */
    async function clearAllNotifications() {
        if (STATE.notifications.length === 0) {
            showToast('info', 'Уведомлений нет', 'Нечего очищать', 2000);
            return;
        }
        if (!confirm('Удалить все уведомления? Это действие необратимо.')) return;

        // ИСПРАВЛЕНО: убрана «тихая» подмена очистки отметкой «прочитано».
        // Фолбэк на mark-read допускается ТОЛЬКО при явном ответе сервера,
        // что эндпоинт не поддерживается (404 или success:false), и всегда
        // сопровождается честным предупреждением пользователя.
        // Ошибки авторизации (401/403) и серверные ошибки (5xx) фолбэка НЕ получают.
        let statusCode = null;

        try {
            const data = await apiCall('notifications/clear');
            if (data && data.success) {
                STATE.notifications = [];
                STATE.unreadNotifCount = 0;
                STATE.knownNotifIds = new Set();
                updateNotifBadge();
                renderNotifications();
                showToast('success', 'Очищено', 'Все уведомления удалены');
                return;
            }
            // Сервер вернул HTTP 200, но success:false — эндпоинт не выполнил очистку
            statusCode = 200;
        } catch (e) {
            const statusMatch = (e.message || '').match(/HTTP (\d{3})/);
            statusCode = statusMatch ? parseInt(statusMatch[1], 10) : null;
        }

        // Разбор кода ответа — вместо прежней единой тихой подмены
        if (statusCode === 401 || statusCode === 403) {
            // Ошибка авторизации: ничего локально не меняем, предлагаем перезайти
            console.error('[CSC] Clear notifications: auth error HTTP ' + statusCode);
            showToast('error', 'Нет доступа',
                'Сессия истекла или токен недействителен. Обновите страницу и повторите попытку.', 5000);
            return;
        }

        if (statusCode !== null && statusCode >= 500) {
            // Серверная ошибка: данные на сервере не тронуты, предупреждаем явно
            console.error('[CSC] Clear notifications: server error HTTP ' + statusCode);
            showToast('error', 'Ошибка сервера',
                'Не удалось удалить уведомления на сервере (HTTP ' + statusCode + '). Попробуйте позже.', 5000);
            return;
        }

        if (statusCode === 404) {
            // Эндпоинт действительно отсутствует — единственный допустимый случай фолбэка
            console.warn('[CSC] Clear endpoint not available (404). Falling back to mark-as-read.');
            showToast('warning', 'Очистка недоступна',
                'Сервер не поддерживает удаление уведомлений. Уведомления будут только помечены как прочитанные, а не удалены.', 6000);
            await markAllNotificationsRead();
            return;
        }

        // Иные ситуации (success:false, сетевые ошибки без кода и т.п.) —
        // показываем точную причину, не подменяя действие молча
        const reason = statusCode
            ? 'Сервер отклонил запрос (HTTP ' + statusCode + ')'
            : 'Сервер не подтвердил удаление';
        console.warn('[CSC] Clear notifications failed:', reason);
        showToast('error', 'Не удалось очистить',
            reason + '. Уведомления не удалены. Попробуйте ещё раз.', 6000);
    }

    /**
     * Открытие панели уведомлений.
     */
    function openNotificationsPanel() {
        const panel = $('#csc-notifications-panel');
        if (!panel) return;
        panel.removeAttribute('hidden');
        STATE.notifPanelOpen = true;
        renderNotifications();

        // При открытии панели — помечаем все как прочитанные
        markAllNotificationsRead();
    }

    /**
     * Закрытие панели уведомлений.
     */
    function closeNotificationsPanel() {
        const panel = $('#csc-notifications-panel');
        if (!panel) return;
        panel.setAttribute('hidden', '');
        STATE.notifPanelOpen = false;
    }

    /**
     * Toggle панели (открыть/закрыть).
     */
    function toggleNotificationsPanel() {
        if (STATE.notifPanelOpen) {
            closeNotificationsPanel();
        } else {
            openNotificationsPanel();
        }
    }

    /**
     * Лёгкая подгрузка бейджа для polling.
     */
    async function loadNotificationBadge() {
        await loadNotifications();
    }

    /**
     * Инициализация всех обработчиков виджета уведомлений.
     * Вызывается один раз при init().
     */
    function initNotificationsWidget() {
        // Кнопка колокольчика — toggle панели
        const toggleBtn = $('#csc-notifications-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleNotificationsPanel();
            });
        }

        // Кнопка "Прочитать все"
        const markReadBtn = $('#csc-mark-all-read');
        if (markReadBtn) {
            markReadBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                markAllNotificationsRead();
            });
        }

        // НОВОЕ: Кнопка "Очистить все"
        const clearBtn = $('#csc-notif-clear-all');
        if (clearBtn) {
            clearBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                clearAllNotifications();
            });
        }

        // Закрытие по клику вне панели
        document.addEventListener('click', (e) => {
            if (!STATE.notifPanelOpen) return;
            const panel = $('#csc-notifications-panel');
            const toggle = $('#csc-notifications-toggle');
            if (!panel || !toggle) return;

            const clickedInsidePanel = panel.contains(e.target);
            const clickedToggle = toggle.contains(e.target);
            if (!clickedInsidePanel && !clickedToggle) {
                closeNotificationsPanel();
            }
        });

        // Закрытие по Escape
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && STATE.notifPanelOpen) {
                closeNotificationsPanel();
            }
        });

        // Предотвращаем закрытие при клике внутри панели
        const panel = $('#csc-notifications-panel');
        if (panel) {
            panel.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
    }
    // ============================================================
    // КОНЕЦ БЛОКА ВИДЖЕТА УВЕДОМЛЕНИЙ
    // ============================================================

    // ============================================================
    // 23. ИНИЦИАЛИЗАЦИЯ (ОБЪЕДИНЕНО 6.2: 5.1 + 6.0 + 6.2 баланс)
    // ============================================================
    function init() {
        initTheme();
        initSoundToggle();
        initTabs();
        initModalEvents();
        initPushSubscription();
        initActivityToggle();  
        initServiceZoneMap();
        initNotificationsWidget();

        Promise.all([
            loadOrders(),
            loadReviews(),
            loadStats(),
            loadNotifications(),
            loadBalance(),
        ]).catch(err => console.error('Init load error:', err));

        startPolling();

        // Отложенная инициализация системы уведомлений мастера
        setTimeout(() => {
            if (typeof initMasterNotifications === 'function') {
                initMasterNotifications();
            }
        }, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

})();