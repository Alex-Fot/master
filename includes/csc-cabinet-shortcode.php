<?php
if (!defined('ABSPATH')) exit;

// =============== ЛИЧНЫЙ КАБИНЕТ ===============
// Версия: 3.0
// ИСПРАВЛЕНО:
// - Убран дублирующий блок res.data.orders.forEach вне .then()
// - Добавлен _wpnonce во все AJAX-запросы (отзыв, отмена, экспорт, поиск)
// - Добавлена проверка 35 дней для выполненных заявок
// - Добавлена загрузка фото к отзыву (до 10 шт., до 5 МБ)
// - Номер заявки стал необязательным (по телефону — все за 6 месяцев)
// v3.0.1: Добавлена поддержка data-theme="dark", экранирование data-id,
//         парсинг даты DD.MM.YYYY, скрытие кнопки уведомлений до авторизации

add_shortcode('csc_personal_cabinet', 'csc_personal_cabinet_shortcode');

function csc_personal_cabinet_shortcode() {
    ob_start();
    $cabinet_nonce = wp_create_nonce('csc_cabinet_nonce');

    // URL звукового файла для уведомлений клиента
    $sound_file_path = WP_PLUGIN_DIR . '/custom-service-calculator/assets/sounds/csc-notify.mp3';
    $sound_url = file_exists($sound_file_path)
        ? plugins_url('custom-service-calculator/assets/sounds/csc-notify.mp3')
        : '';
?>
<style>
:root {
    --csc-primary: #3b82f6;
    --csc-primary-dark: #2563eb;
    --csc-bg: #f8fafc;
    --csc-card: #ffffff;
    --csc-text: #1e293b;
    --csc-border: #e2e8f0;
    --csc-success: #10b981;
    --csc-danger: #ef4444;
    --csc-warning: #f59e0b;
    --csc-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
}
@media (prefers-color-scheme: dark) {
    :root {
        --csc-bg: #0f172a;
        --csc-card: #1e293b;
        --csc-text: #f1f5f9;
        --csc-border: #334155;
    }
}
/* [ИСПРАВЛЕНО #4] Поддержка ручного переключения темы через JS (data-theme="dark") */
[data-theme="dark"] {
    --csc-bg: #0f172a;
    --csc-card: #1e293b;
    --csc-text: #f1f5f9;
    --csc-border: #334155;
}
#csc-cabinet {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    background: var(--csc-bg);
    color: var(--csc-text);
    padding: 16px;
    max-width: 800px;
    margin: 0 auto;
}
.csc-toast {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%);
    background: var(--csc-primary);
    color: white;
    padding: 14px 24px;
    border-radius: 12px;
    z-index: 1000;
    display: none;
    box-shadow: var(--csc-shadow);
    font-weight: 500;
    animation: fadeInOut 0.3s ease;
}
.csc-toast.error { background: var(--csc-danger); }
.csc-toast.info { background: #64748b; }
@keyframes fadeInOut {
    from { opacity: 0; transform: translate(-50%, -10px); }
    to   { opacity: 1; transform: translate(-50%, 0); }
}
.csc-login-card {
    background: var(--csc-card);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: var(--csc-shadow);
}
.csc-login-title { font-size: 20px; font-weight: 700; margin-bottom: 16px; text-align: center; }
.csc-login-instruction { text-align: center; color: #64748b; margin-bottom: 20px; line-height: 1.5; font-size: 14px; }
.csc-form-group { margin: 16px 0; }
.csc-form-label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 15px; }
.csc-form-input {
    width: 100%;
    padding: 14px 16px;
    border: none;
    border-bottom: 2px solid var(--csc-border);
    background: transparent;
    color: var(--csc-text);
    font-size: 16px;
    box-sizing: border-box;
}
.csc-form-input:focus { outline: none; border-bottom-color: var(--csc-primary); }
.csc-login-btn {
    background: linear-gradient(135deg, var(--csc-primary), var(--csc-primary-dark));
    color: white;
    border: none;
    padding: 16px;
    border-radius: 14px;
    font-size: 17px;
    font-weight: 600;
    cursor: pointer;
    width: 100%;
    margin-top: 10px;
    box-shadow: var(--csc-shadow);
    transition: all 0.2s ease;
}
.csc-login-btn:active { transform: scale(0.98); }
.csc-orders-card {
    background: var(--csc-card);
    border-radius: 16px;
    padding: 20px;
    box-shadow: var(--csc-shadow);
}
.csc-orders-title { font-size: 18px; font-weight: 700; margin-bottom: 8px; color: var(--csc-primary-dark); }
.csc-orders-subtitle { font-size: 13px; color: #64748b; margin-bottom: 12px; }
.csc-order-table { width: 100%; border-collapse: collapse; }
.csc-order-table th, .csc-order-table td {
    padding: 12px 8px;
    text-align: left;
    border-bottom: 1px solid var(--csc-border);
    vertical-align: top;
}
.csc-order-table th { font-weight: 600; color: #64748b; font-size: 14px; }
.csc-order-status.cancelled { color: var(--csc-danger); font-weight: 600; }
.csc-order-status.pending-pay { color: var(--csc-warning); font-weight: 600; }
.csc-order-expired { font-size: 11px; color: #94a3b8; font-style: italic; }
.csc-cancel-btn, .csc-review-btn {
    border: none;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 14px;
    cursor: pointer;
    transition: opacity 0.2s;
    color: #fff;
}
.csc-cancel-btn { background: var(--csc-danger); }
.csc-review-btn { background: var(--csc-primary); }
.csc-cancel-btn:hover { background: #dc2626; }
.csc-review-btn:hover { background: var(--csc-primary-dark); }
.csc-cancel-btn:disabled, .csc-review-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.csc-rating-display { color: var(--csc-warning); font-weight: bold; font-size: 15px; }
.csc-export-btn {
    display: inline-block;
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    text-decoration: none;
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 600;
    margin-top: 16px;
    box-shadow: var(--csc-shadow);
}
/* Модальное окно отзыва */
.csc-modal-overlay {
    position: fixed;
    top: 0; left: 0; width: 100%; height: 100%;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    z-index: 2000;
    display: none;
    justify-content: center;
    align-items: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}
.csc-modal-overlay.active { display: flex; opacity: 1; }
.csc-modal-content {
    background: var(--csc-card);
    padding: 30px;
    border-radius: 20px;
    width: 92%;
    max-width: 440px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
    transform: scale(0.9);
    transition: transform 0.3s cubic-bezier(0.34,1.56,0.64,1);
    text-align: center;
    color: var(--csc-text);
}
.csc-modal-overlay.active .csc-modal-content { transform: scale(1); }
.csc-modal-title { font-size: 20px; font-weight: 700; margin-bottom: 8px; }
.csc-modal-subtitle { font-size: 14px; color: #64748b; margin-bottom: 20px; }
.csc-stars-container { display: flex; justify-content: center; gap: 10px; margin-bottom: 20px; direction: ltr; }
.csc-star-btn {
    background: none; border: none; font-size: 40px;
    cursor: pointer; color: #e2e8f0; padding: 0; line-height: 1;
    transition: color 0.2s, transform 0.2s;
}
.csc-star-btn:hover, .csc-star-btn.active { color: var(--csc-warning); transform: scale(1.1); }
.csc-comment-area {
    width: 100%; padding: 12px; border: 1px solid var(--csc-border);
    border-radius: 10px; background: var(--csc-bg); color: var(--csc-text);
    font-family: inherit; font-size: 14px; resize: vertical;
    min-height: 80px; margin-bottom: 16px; box-sizing: border-box;
}
.csc-comment-area:focus { outline: none; border-color: var(--csc-primary); background: var(--csc-card); }
/* Загрузка фото к отзыву */
.csc-review-upload-area { margin-bottom: 16px; text-align: left; }
.csc-review-upload-dropzone {
    border: 2px dashed var(--csc-border);
    border-radius: 12px;
    padding: 20px 16px;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
    text-align: center;
    font-size: 14px;
    color: #64748b;
}
.csc-review-upload-dropzone:hover,
.csc-review-upload-dropzone.dragover {
    border-color: var(--csc-primary);
    background: rgba(59,130,246,0.04);
}
.csc-review-upload-hint { font-size: 12px; color: #94a3b8; margin-top: 6px; }
.csc-review-photos-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.csc-review-photo-item {
    position: relative;
    width: 72px; height: 72px;
    border-radius: 8px; overflow: hidden;
    border: 1px solid var(--csc-border);
}
.csc-review-photo-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.csc-review-photo-remove {
    position: absolute; top: 2px; right: 2px;
    width: 20px; height: 20px; border-radius: 50%;
    background: rgba(239,68,68,0.9); color: #fff; border: none;
    font-size: 12px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    line-height: 1; padding: 0;
}
.csc-review-progress {
    margin-bottom: 12px; height: 6px;
    background: var(--csc-border); border-radius: 3px;
    overflow: hidden; display: none;
}
.csc-review-progress-bar {
    height: 100%; width: 0;
    background: linear-gradient(90deg, var(--csc-primary), var(--csc-success));
    transition: width 0.3s ease;
}
.csc-modal-actions { display: flex; gap: 10px; justify-content: center; margin-top: 8px; }
.csc-modal-btn {
    padding: 10px 20px; border-radius: 8px; font-weight: 600;
    font-size: 14px; cursor: pointer; border: none; transition: opacity 0.2s;
}
.csc-modal-btn-cancel { background: transparent; color: #64748b; border: 1px solid var(--csc-border); }
.csc-modal-btn-submit { background: var(--csc-primary); color: white; }
.csc-modal-btn:disabled { opacity: 0.5; cursor: not-allowed; }
@media (max-width: 600px) {
    .csc-order-table th:nth-child(3),
    .csc-order-table td:nth-child(3) { display: none; }
}

/* [ИСПРАВЛЕНО #5] Кнопка уведомлений скрыта до авторизации */
#csc-cabinet:not(.logged-in) .csc-notifications-client-btn {
    display: none;
}

/* ============================================================
   КНОПКА УВЕДОМЛЕНИЙ КЛИЕНТА
============================================================ */
.csc-notifications-client-btn {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--csc-primary), var(--csc-primary-dark));
    color: white;
    border: none;
    font-size: 24px;
    cursor: pointer;
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
    z-index: 1500;
    transition: transform 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}
.csc-notifications-client-btn:hover { transform: scale(1.1); }
.csc-notif-badge {
    position: absolute;
    top: -4px;
    right: -4px;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    background: #ef4444;
    color: white;
    font-size: 11px;
    font-weight: 700;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid white;
}

/* ============================================================
   МОДАЛКА УВЕДОМЛЕНИЙ КЛИЕНТА
============================================================ */
.csc-client-notif-modal {
    position: fixed;
    bottom: 90px;
    right: 24px;
    width: 360px;
    max-width: calc(100vw - 40px);
    max-height: 70vh;
    background: var(--csc-card);
    border-radius: 16px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    z-index: 1600;
    display: none;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid var(--csc-border);
}
.csc-client-notif-modal.active { display: flex; }
.csc-client-notif-header {
    padding: 14px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--csc-border);
    background: var(--csc-bg);
}
.csc-client-notif-header h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--csc-text);
}
.csc-client-notif-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}
.csc-client-notif-mark-read {
    background: transparent;
    border: none;
    color: var(--csc-primary);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.csc-client-notif-close {
    background: transparent;
    border: none;
    font-size: 18px;
    cursor: pointer;
    color: var(--csc-text);
    line-height: 1;
    padding: 4px;
}
.csc-client-notif-list {
    overflow-y: auto;
    flex: 1;
}
.csc-client-notif-item {
    padding: 12px 16px;
    border-bottom: 1px solid var(--csc-border);
    cursor: pointer;
    transition: background 0.15s;
    display: flex;
    gap: 10px;
}
.csc-client-notif-item:hover { background: var(--csc-bg); }
.csc-client-notif-item.unread { background: rgba(59, 130, 246, 0.05); }
.csc-client-notif-icon-big { font-size: 22px; flex-shrink: 0; }
.csc-client-notif-body { flex: 1; min-width: 0; }
.csc-client-notif-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--csc-text);
    margin-bottom: 2px;
}
.csc-client-notif-msg {
    font-size: 12px;
    color: var(--csc-text);
    opacity: 0.8;
    line-height: 1.4;
}
.csc-client-notif-time {
    font-size: 11px;
    color: var(--csc-text);
    opacity: 0.5;
    margin-top: 4px;
}
.csc-client-notif-empty {
    padding: 40px 20px;
    text-align: center;
    color: var(--csc-text);
    opacity: 0.5;
    font-size: 13px;
}
</style>

<div id="csc-cabinet">
    <div class="csc-toast" id="csc-toast"></div>

    <!-- Кнопка уведомлений клиента -->
    <button type="button" class="csc-notifications-client-btn" id="csc-client-notif-btn" title="Уведомления">
        <span class="csc-notif-icon">💬</span>
        <span class="csc-notif-badge" id="csc-client-notif-badge" hidden>0</span>
    </button>

    <!-- Модалка уведомлений клиента -->
    <div class="csc-client-notif-modal" id="csc-client-notif-modal">
        <div class="csc-client-notif-header">
            <h3>📨 Уведомления</h3>
            <div class="csc-client-notif-actions">
                <button type="button" class="csc-client-notif-mark-read" id="csc-client-mark-read">Прочитать все</button>
                <button type="button" class="csc-client-notif-close" id="csc-client-notif-close">✕</button>
            </div>
        </div>
        <div class="csc-client-notif-list" id="csc-client-notif-list">
            <div class="csc-client-notif-empty">Нет уведомлений</div>
        </div>
    </div>

    <div class="csc-login-card">
        <div class="csc-login-title">Личный кабинет</div>
        <div class="csc-login-instruction">
            Введите ваш номер телефона.<br>
            Номер заявки — необязателен: укажите его, чтобы найти конкретную заявку.<br>
            Без номера заявки отображаются все заявки за последние 6 месяцев.
        </div>
        <form id="csc-login-form">
            <div class="csc-form-group">
                <label class="csc-form-label">Телефон *</label>
                <input type="tel" name="phone" id="csc-cab-phone" class="csc-form-input"
                       pattern="[+]?[0-9\s\-\(\)]{10,20}" required placeholder="+7 (999) 123-45-67">
            </div>
            <div class="csc-form-group">
                <label class="csc-form-label">Номер заявки (необязательно)</label>
                <input type="text" name="order_number" id="csc-cab-order" class="csc-form-input"
                       placeholder="CSC-20260113-00001">
            </div>
            <button type="submit" class="csc-login-btn">Найти заявки</button>
        </form>
    </div>

    <div id="csc-orders-result"></div>

    <!-- Модальное окно отзыва -->
    <div class="csc-modal-overlay" id="csc-review-modal">
        <div class="csc-modal-content">
            <h3 class="csc-modal-title">Оцените услугу</h3>
            <p class="csc-modal-subtitle">Нажмите на звёзды, чтобы поставить оценку</p>
            <div class="csc-stars-container" id="csc-stars-wrapper">
                <button type="button" class="csc-star-btn" data-value="1">★</button>
                <button type="button" class="csc-star-btn" data-value="2">★</button>
                <button type="button" class="csc-star-btn" data-value="3">★</button>
                <button type="button" class="csc-star-btn" data-value="4">★</button>
                <button type="button" class="csc-star-btn" data-value="5">★</button>
            </div>
            <textarea class="csc-comment-area" id="csc-review-comment"
                      maxlength="1000"
                      placeholder="Напишите ваш отзыв (необязательно, до 1000 символов)..."></textarea>
            <!-- Загрузка фото к отзыву -->
            <div class="csc-review-upload-area">
                <div class="csc-review-upload-dropzone" id="csc-review-dropzone">
                    📷 Нажмите или перетащите фото сюда
                    <input type="file" id="csc-review-file-input" multiple
                           accept="image/jpeg,image/png,image/webp,image/heic"
                           style="display:none;">
                    <div class="csc-review-upload-hint">До 10 фото, до 5 МБ каждое (JPG, PNG, WebP, HEIC)</div>
                </div>
                <div class="csc-review-photos-grid" id="csc-review-photos-grid"></div>
            </div>
            <div class="csc-review-progress" id="csc-review-progress">
                <div class="csc-review-progress-bar" id="csc-review-progress-bar"></div>
            </div>
            <div class="csc-modal-actions">
                <button type="button" class="csc-modal-btn csc-modal-btn-cancel" id="csc-review-cancel-btn">Отмена</button>
                <button type="button" class="csc-modal-btn csc-modal-btn-submit" id="csc-submit-review-btn" disabled>Отправить</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

// ============================================================
// КОНФИГУРАЦИЯ
// ============================================================
const AJAX_URL = '<?php echo esc_js(admin_url("admin-ajax.php")); ?>';
const NONCE    = '<?php echo esc_js($cabinet_nonce); ?>';
const REVIEW_EXPIRY_DAYS = 35;
const MAX_REVIEW_PHOTOS  = 10;
const MAX_REVIEW_PHOTO_SIZE = 5 * 1024 * 1024; // 5 МБ
const SOUND_URL = '<?php echo esc_js($sound_url); ?>';
// i18n: локализованные подписи статусов из PHP (csc_status_labels_js — переводятся gettext на сервере)
const CSC_STATUS_LABELS = <?php echo wp_json_encode(function_exists('csc_status_labels_js') ? csc_status_labels_js() : new stdClass()); ?>;

// ИСПРАВЛЕНО (защита ЛК): токен сессии клиента хранится только в памяти страницы.
// Выдаётся сервером при входе (res.data.auth_token) и подставляется в запросы,
// требующие подтверждения доступа (платежи). В URL/cookie/localStorage не попадает.
let cscSessionToken = '';

// ИСПРАВЛЕНО (защита ЛК): токен сессии клиента хранится только в памяти страницы.
// Выдаётся сервером при входе (res.data.auth_token) и подставляется в запросы,
// требующие подтверждения доступа (платежи). В URL/cookie/localStorage не попадает.
let cscSessionToken = '';

// ============================================================
// СОСТОЯНИЕ
// ============================================================
let currentReviewOrder = '';
let currentReviewPhone = '';
let currentRatingValue = 0;
let reviewPhotoFiles   = [];

// ============================================================
// УТИЛИТЫ
// ============================================================
function showToast(message, type) {
    const t = document.getElementById('csc-toast');
    if (!t) return;
    t.textContent = message;
    t.className = 'csc-toast' + (type === 'error' ? ' error' : (type === 'info' ? ' info' : ''));
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 5000);
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

/**
 * Проверка: старше ли заявка 35 дней с момента завершения.
 */
function isOrderExpired(order) {
    if (!order.completed_at) return false;
    const completed = new Date(order.completed_at.replace(' ', 'T'));
    if (isNaN(completed.getTime())) return false;
    const diffDays = (Date.now() - completed.getTime()) / (1000 * 60 * 60 * 24);
    return diffDays > REVIEW_EXPIRY_DAYS;
}

/**
 * [ИСПРАВЛЕНО #3] Парсинг даты: поддержка форматов YYYY-MM-DD и DD.MM.YYYY
 */
function parseOrderDate(dateStr, timeStr) {
    let isoDate = dateStr || '';
    if (/^\d{2}\.\d{2}\.\d{4}$/.test(isoDate)) {
        const parts = isoDate.split('.');
        isoDate = parts[2] + '-' + parts[1] + '-' + parts[0];
    }
    const dt = new Date(isoDate + 'T' + (timeStr || '00:00') + ':00');
    return isNaN(dt.getTime()) ? null : dt;
}

// ============================================================
// ИНТЕГРАЦИЯ: УВЕДОМЛЕНИЯ И PUSH
// ============================================================
function markNotificationsRead(phone) {
    const fd = new FormData();
    fd.append('action', 'csc_mark_read');
    fd.append('type', 'customer');
    fd.append('id', phone);
    fd.append('_wpnonce', NONCE);
    fetch(AJAX_URL, { method: 'POST', body: fd })
        .then(r => r.json()).catch(() => {});
}

function urlBase64ToUint8Array(base64String) {
    if (!base64String) return null;
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    try {
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
        return outputArray;
    } catch (e) { return null; }
}

function initPushSubscription(phone) {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    const vapidKey = '<?php echo esc_js(get_option("csc_vapid_public_key", "")); ?>';
    if (!vapidKey) return;
    const vapidArray = urlBase64ToUint8Array(vapidKey);
    if (!vapidArray) return;

    Notification.requestPermission().then(perm => {
        if (perm !== 'granted') return;

        // ИСПРАВЛЕНО: явная регистрация SW из корня
        navigator.serviceWorker.getRegistration('/').then(reg => {
            if (!reg) {
                return navigator.serviceWorker.register('/csc-sw.js', { scope: '/' })
                    .then(newReg => navigator.serviceWorker.ready)
                    .then(() => navigator.serviceWorker.getRegistration('/'));
            }
            return reg;
        }).then(reg => {
            if (!reg) return;
            return reg.pushManager.getSubscription().then(existing => {
                if (existing && !existing.expirationTime) {
                    return existing; // переиспользуем валидную
                }
                return reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: vapidArray
                });
            }).then(sub => {
                const fd = new FormData();
                fd.append('action', 'csc_push_subscribe');
                fd.append('user_type', 'customer');
                fd.append('user_id', phone);
                fd.append('subscription', JSON.stringify(sub));
                fd.append('_wpnonce', NONCE);
                fetch(AJAX_URL, { method: 'POST', body: fd });
            });
        }).catch(err => console.warn('[CSC] Push subscription error:', err));
    });
}

// ============================================================
// КЛИЕНТСКИЕ УВЕДОМЛЕНИЯ (КНОПКА 💬 + МОДАЛКА)
// ============================================================
const CSC_CLIENT_NOTIFICATIONS = {
    phone: '',
    list: [],
    interval: null,
    sound: null,

    init: function(phone) {
        this.phone = phone.replace(/\D/g, '');
        if (!this.phone) return;
        this.load();
        this.startPolling();
        this.initSound();
        this.bindEvents();
    },

    initSound: function() {
        if (SOUND_URL && SOUND_URL.trim() !== '') {
            const audio = new Audio(SOUND_URL);
            audio.preload = 'auto';
            audio.volume = 1.0;
            audio.addEventListener('error', () => { this.sound = null; });
            audio.addEventListener('canplaythrough', () => { this.sound = audio; });
            this.sound = audio;
        } else {
            this.sound = null;
        }
    },

    playSound: function() {
        if (this.sound) {
            try {
                this.sound.currentTime = 0;
                this.sound.play().catch(() => {});
            } catch (e) {}
        } else {
            // Фолбэк: генерируем бип через Web Audio API
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.3, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                osc.start();
                osc.stop(ctx.currentTime + 0.3);
            } catch (e) {}
        }
    },

    bindEvents: function() {
        const btn = document.getElementById('csc-client-notif-btn');
        const modal = document.getElementById('csc-client-notif-modal');
        const closeBtn = document.getElementById('csc-client-notif-close');
        const markBtn = document.getElementById('csc-client-mark-read');

        if (btn) btn.addEventListener('click', () => modal.classList.toggle('active'));
        if (closeBtn) closeBtn.addEventListener('click', () => modal.classList.remove('active'));
        if (markBtn) markBtn.addEventListener('click', () => this.markAllRead());
    },

    startPolling: function() {
        this.interval = setInterval(() => this.load(), 15000);
    },

    load: function() {
        if (!this.phone) return;
        const fd = new FormData();
        fd.append('action', 'csc_get_customer_notifications');
        fd.append('phone', this.phone);
        fd.append('_wpnonce', NONCE);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success && res.data) {
                    const oldUnread = this.list.filter(n => !n.read).length;
                    const newUnread = (res.data.notifications || []).filter(n => !n.read).length;
                    this.list = res.data.notifications || [];
                    this.render();
                    if (newUnread > oldUnread && oldUnread >= 0) {
                        this.playSound();
                    }
                }
            })
            .catch(() => {});
    },

    render: function() {
        const badge = document.getElementById('csc-client-notif-badge');
        const list = document.getElementById('csc-client-notif-list');
        const unread = this.list.filter(n => !n.read).length;

        if (badge) {
            if (unread > 0) {
                badge.textContent = unread > 99 ? '99+' : unread;
                badge.removeAttribute('hidden');
            } else {
                badge.setAttribute('hidden', '');
            }
        }

        if (!list) return;
        if (this.list.length === 0) {
            list.innerHTML = '<div class="csc-client-notif-empty">Нет уведомлений</div>';
            return;
        }

        // [ИСПРАВЛЕНО #2] data-id экранируется через this.escape()
        list.innerHTML = this.list.map(n => `
            <div class="csc-client-notif-item ${n.read ? '' : 'unread'}" data-id="${this.escape(n.id)}">
                <div class="csc-client-notif-icon-big">${this.escape(n.icon) || '📩'}</div>
                <div class="csc-client-notif-body">
                    <div class="csc-client-notif-title">${this.escape(n.title)}</div>
                    <div class="csc-client-notif-msg">${this.escape(n.message)}</div>
                    <div class="csc-client-notif-time">${this.escape(n.time_ago) || this.escape(n.created_at) || ''}</div>
                </div>
            </div>
        `).join('');
    },

    markAllRead: function() {
        const fd = new FormData();
        fd.append('action', 'csc_mark_customer_notifications_read');
        fd.append('phone', this.phone);
        fd.append('_wpnonce', NONCE);
        fetch(AJAX_URL, { method: 'POST', body: fd }).then(() => {
            this.list.forEach(n => n.read = true);
            this.render();
        });
    },

    escape: function(s) {
        if (s === null || s === undefined) return '';
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }
};

// ============================================================
// ПОИСК ЗАЯВОК
// ============================================================
document.getElementById('csc-login-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const phoneRaw  = this.phone.value.trim();
    const orderNum  = this.order_number.value.trim();
    const submitBtn = this.querySelector('.csc-login-btn');

    if (!/^[+]?[0-9\s\-\(\)]{10,20}$/.test(phoneRaw)) {
        showToast('Введите корректный номер телефона', 'error');
        return;
    }

    const resultDiv = document.getElementById('csc-orders-result');
    resultDiv.innerHTML = '<p style="text-align:center; margin-top:20px; color:#64748b;">Загрузка...</p>';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Поиск...';

    const fd = new FormData();
    fd.append('action', 'csc_get_user_orders');
    fd.append('phone', phoneRaw);
    fd.append('order_number', orderNum);
    // ИСПРАВЛЕНО (защита ЛК): предъявляем токен сессии из памяти страницы,
    // если он уже был выдан сервером при предыдущем входе.
    fd.append('auth_token', cscSessionToken || '');
    fd.append('_wpnonce', NONCE);

    fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Найти заявки';
        if (res.success && res.data && res.data.orders && res.data.orders.length > 0) {
            // Сохраняем выданный сервером токен сессии (только в памяти страницы)
            if (res.data.auth_token) { cscSessionToken = res.data.auth_token; }
            const phoneNorm = phoneRaw.replace(/[^0-9]/g, '');
            markNotificationsRead(phoneNorm);
            initPushSubscription(phoneNorm);
            renderOrders(res.data.orders, res.data);
            // Инициализация клиентских уведомлений после первого успешного входа
            if (!CSC_CLIENT_NOTIFICATIONS.phone) {
                CSC_CLIENT_NOTIFICATIONS.init(phoneNorm);
            }
        } else {
            const msg = (res.data && res.data.message) ? res.data.message : 'Заявки не найдены. Проверьте данные.';
            resultDiv.innerHTML = '<div class="csc-orders-card"><p style="text-align:center; color:#ef4444;">' + escapeHtml(msg) + '</p></div>';
        }
    })
    .catch(() => {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Найти заявки';
        resultDiv.innerHTML = '<div class="csc-orders-card"><p style="text-align:center; color:#ef4444;">Ошибка сети. Попробуйте позже.</p></div>';
    });
});

// ============================================================
// РЕНДЕР ТАБЛИЦЫ ЗАЯВОК
// ============================================================
function renderOrders(orders, meta) {
    // [ИСПРАВЛЕНО #5] Помечаем кабинет как авторизованный — показываем кнопку уведомлений
    const cabinetEl = document.getElementById('csc-cabinet');
    if (cabinetEl) cabinetEl.classList.add('logged-in');

    const resultDiv = document.getElementById('csc-orders-result');
    const filterMode = (meta && meta.filter_mode === 'single') ? 'single' : 'all';
    const subtitle = filterMode === 'single'
        ? 'Найдена заявка по номеру'
        : 'Заявки за последние 6 месяцев (' + orders.length + ' шт.)';

    let html = '<div class="csc-orders-card">';
    html += '<div class="csc-orders-title">Ваши заявки</div>';
    html += '<div class="csc-orders-subtitle">' + escapeHtml(subtitle) + '</div>';
    html += '<table class="csc-order-table"><thead><tr>';
    html += '<th>Номер</th><th>Дата/время</th><th>Статус</th><th>Сумма</th><th>Действия</th>';
    html += '</tr></thead><tbody>';

    orders.forEach(o => {
        // i18n: подписи берутся из CSC_STATUS_LABELS (wp_json_encode из csc_status_labels_js),
        // словарь ниже — фолбэк на случай, если PHP-карта не передалась.
        const STATUS_FALLBACK = { pending:'Ожидает', searching:'Поиск мастера', awaiting_payment:'Ожидает оплаты', processing:'В работе', completed:'Выполнено', cancelled:'Отменено', no_masters:'Нет мастеров', routing_timeout:'Ошибка: время поиска истекло' };
        const rawStatus = ((o.status || 'pending') + '').toLowerCase();
        const statusText = (typeof CSC_STATUS_LABELS === 'object' && CSC_STATUS_LABELS[rawStatus]) ? CSC_STATUS_LABELS[rawStatus] : (STATUS_FALLBACK[rawStatus] || rawStatus);
        const isCancelled = !!o.cancelled_at || rawStatus === 'cancelled';
                let statusClass = '';
        if (isCancelled) statusClass = 'cancelled';
        else if (rawStatus === 'awaiting_payment') statusClass = 'pending-pay';

        const expired = isOrderExpired(o);
        let actionBtn = '';

        if (expired) {
            // Заявка старше 35 дней — никаких действий
            if (o.review && o.review.rating) {
                actionBtn = '<div class="csc-rating-display">⭐ ' + escapeHtml(o.review.rating) + '/5</div>';
                actionBtn += '<div class="csc-order-expired">срок действий истёк</div>';
            } else {
                actionBtn = '<span class="csc-order-expired">срок действий истёк</span>';
            }
        } else if (rawStatus === 'completed') {
            if (o.review && o.review.rating) {
                actionBtn = '<div class="csc-rating-display">⭐ ' + escapeHtml(o.review.rating) + '/5</div>';
            } else {
                actionBtn = '<button type="button" class="csc-review-btn" data-order="' + escapeHtml(o.order_number) + '" data-phone="' + escapeHtml(o.phone || '') + '">Оценить</button>';
            }
        } else if (isCancelled) {
            actionBtn = '<em>отменено</em>';
        } else {
            // [ИСПРАВЛЕНО #3] Парсинг даты с поддержкой DD.MM.YYYY
            const startDT = parseOrderDate(o.date, o.time);
            const hoursDiff = startDT ? (startDT.getTime() - Date.now()) / (1000 * 60 * 60) : -1;
            if (hoursDiff >= 12) {
                actionBtn = '<button type="button" class="csc-cancel-btn" data-order="' + escapeHtml(o.order_number) + '">Отменить</button>';
            } else {
                actionBtn = '<em style="color:#94a3b8;">нельзя отменить</em>';
            }
        }

        html += '<tr>';
        html += '<td>' + escapeHtml(o.order_number) + '</td>';
        html += '<td>' + escapeHtml(o.date || '') + ' ' + escapeHtml(o.time || '') + '</td>';
        html += '<td><span class="csc-order-status ' + statusClass + '">' + escapeHtml(statusText) + '</span></td>';
        html += '<td>' + (parseFloat(o.total) || 0).toLocaleString('ru-RU') + ' ₽</td>';
        html += '<td>' + actionBtn + '</td>';
        html += '</tr>';
    });

    html += '</tbody></table>';

    if (filterMode === 'all' && orders.length > 0) {
        const phoneNorm = (orders[0].phone || '').replace(/[^0-9]/g, '');
        html += '<a href="' + AJAX_URL + '?action=csc_export_orders_csv&phone=' + encodeURIComponent(phoneNorm) + '&_wpnonce=' + encodeURIComponent(NONCE) + '" target="_blank" class="csc-export-btn">📥 Экспорт в CSV</a>';
    }
    html += '</div>';
    resultDiv.innerHTML = html;
    bindOrderActions();
}

// ============================================================
// ПРИВЯЗКА ОБРАБОТЧИКОВ КНОПОК В ТАБЛИЦЕ
// ============================================================
function bindOrderActions() {
    document.querySelectorAll('.csc-review-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            openReviewModal(this.dataset.order, this.dataset.phone);
        });
    });
    document.querySelectorAll('.csc-cancel-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            cancelOrder(this.dataset.order);
        });
    });
}

// ============================================================
// ОТМЕНА ЗАКАЗА
// ============================================================
function cancelOrder(orderNum) {
    const reason = prompt('Укажите причину отмены (минимум 5 символов):');
    if (!reason || reason.trim().length < 5) {
        showToast('Причина обязательна (минимум 5 символов)', 'error');
        return;
    }
    const phoneRaw = document.getElementById('csc-cab-phone').value.trim();
    const fd = new FormData();
    fd.append('action', 'csc_cancel_order');
    fd.append('order_number', orderNum);
    fd.append('reason', reason.trim());
    fd.append('phone', phoneRaw);
    fd.append('_wpnonce', NONCE);

    fetch(AJAX_URL, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            showToast('✅ Заявка успешно отменена!');
            document.getElementById('csc-login-form').dispatchEvent(new Event('submit'));
        } else {
            showToast('❌ ' + ((res.data && res.data.message) || 'Не удалось отменить заявку'), 'error');
        }
    })
    .catch(() => showToast('Ошибка сети', 'error'));
}

// ============================================================
// МОДАЛЬНОЕ ОКНО ОТЗЫВА
// ============================================================
function openReviewModal(orderNumber, phone) {
    currentReviewOrder = orderNumber;
    currentReviewPhone = phone;
    currentRatingValue = 0;
    reviewPhotoFiles   = [];

    document.querySelectorAll('.csc-star-btn').forEach(b => {
        b.classList.remove('active');
        b.style.color = '#e2e8f0';
    });
    document.getElementById('csc-review-comment').value = '';
    document.getElementById('csc-review-photos-grid').innerHTML = '';
    document.getElementById('csc-review-file-input').value = '';
    document.getElementById('csc-review-progress').style.display = 'none';
    document.getElementById('csc-submit-review-btn').disabled = true;
    document.getElementById('csc-submit-review-btn').textContent = 'Отправить';

    const modal = document.getElementById('csc-review-modal');
    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('active'), 10);
}

function closeReviewModal() {
    const modal = document.getElementById('csc-review-modal');
    modal.classList.remove('active');
    setTimeout(() => { modal.style.display = 'none'; }, 300);
}

document.getElementById('csc-review-cancel-btn').addEventListener('click', closeReviewModal);
document.getElementById('csc-review-modal').addEventListener('click', function(e) {
    if (e.target === this) closeReviewModal();
});

// ============================================================
// ЗВЁЗДЫ РЕЙТИНГА
// ============================================================
(function initStars() {
    const stars = document.querySelectorAll('.csc-star-btn');
    const submitBtn = document.getElementById('csc-submit-review-btn');
    stars.forEach(star => {
        star.addEventListener('click', function() {
            currentRatingValue = parseInt(this.dataset.value, 10);
            stars.forEach(s => {
                const sv = parseInt(s.dataset.value, 10);
                if (sv <= currentRatingValue) {
                    s.classList.add('active');
                    s.style.color = 'var(--csc-warning)';
                } else {
                    s.classList.remove('active');
                    s.style.color = '#e2e8f0';
                }
            });
            submitBtn.disabled = false;
        });
        star.addEventListener('mouseover', function() {
            const val = parseInt(this.dataset.value, 10);
            stars.forEach(s => {
                s.style.color = parseInt(s.dataset.value, 10) <= val ? 'var(--csc-warning)' : '#e2e8f0';
            });
        });
        star.addEventListener('mouseout', function() {
            stars.forEach(s => {
                s.style.color = parseInt(s.dataset.value, 10) <= currentRatingValue ? 'var(--csc-warning)' : '#e2e8f0';
            });
        });
    });
})();

// ============================================================
// ЗАГРУЗКА ФОТО К ОТЗЫВУ
// ============================================================
const dropzone  = document.getElementById('csc-review-dropzone');
const fileInput = document.getElementById('csc-review-file-input');
const photosGrid = document.getElementById('csc-review-photos-grid');

dropzone.addEventListener('click', () => fileInput.click());
dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
dropzone.addEventListener('drop', e => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    addPhotos(e.dataTransfer.files);
});
fileInput.addEventListener('change', function() {
    addPhotos(this.files);
    this.value = '';
});

function addPhotos(fileList) {
    const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];
    for (let i = 0; i < fileList.length; i++) {
        const f = fileList[i];
        if (reviewPhotoFiles.length >= MAX_REVIEW_PHOTOS) {
            showToast('Максимум ' + MAX_REVIEW_PHOTOS + ' фото', 'error');
            break;
        }
        const ext = f.name.split('.').pop().toLowerCase();
        const typeOk = allowed.indexOf(f.type) !== -1 ||
                       ['jpg','jpeg','png','webp','heic'].indexOf(ext) !== -1;
        if (!typeOk) {
            showToast('Недопустимый формат: ' + f.name, 'error');
            continue;
        }
        if (f.size > MAX_REVIEW_PHOTO_SIZE) {
            showToast('Файл больше 5 МБ: ' + f.name, 'error');
            continue;
        }
        reviewPhotoFiles.push(f);
    }
    renderPhotos();
}

function renderPhotos() {
    photosGrid.innerHTML = '';
    reviewPhotoFiles.forEach((f, idx) => {
        const item = document.createElement('div');
        item.className = 'csc-review-photo-item';
        const img = document.createElement('img');
        img.src = URL.createObjectURL(f);
        img.onload = () => URL.revokeObjectURL(img.src);
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'csc-review-photo-remove';
        removeBtn.textContent = '✕';
        removeBtn.addEventListener('click', () => {
            reviewPhotoFiles.splice(idx, 1);
            renderPhotos();
        });
        item.appendChild(img);
        item.appendChild(removeBtn);
        photosGrid.appendChild(item);
    });
}

// ============================================================
// ОТПРАВКА ОТЗЫВА (С _wpnonce И ФОТО)
// ============================================================
document.getElementById('csc-submit-review-btn').addEventListener('click', function() {
    if (currentRatingValue === 0) return;

    const submitBtn   = this;
    const comment     = document.getElementById('csc-review-comment').value.trim();
    const progressEl  = document.getElementById('csc-review-progress');
    const progressBar = document.getElementById('csc-review-progress-bar');

    submitBtn.disabled = true;
    submitBtn.textContent = 'Отправка...';
    progressEl.style.display = 'block';
    progressBar.style.width = '0%';

    const fd = new FormData();
    fd.append('action', 'csc_submit_review');
    fd.append('_wpnonce', NONCE);
    fd.append('order_number', currentReviewOrder);
    fd.append('phone', currentReviewPhone);
    fd.append('rating', currentRatingValue);
    fd.append('comment', comment);

    // Прикрепляем фото
    reviewPhotoFiles.forEach(f => {
        fd.append('review_photos[]', f);
    });

    // XHR для отслеживания прогресса загрузки фото
    const xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX_URL);

    xhr.upload.addEventListener('progress', function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            progressBar.style.width = pct + '%';
        }
    });

    xhr.onload = function() {
        progressEl.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Отправить';
        try {
            const res = JSON.parse(xhr.responseText);
            if (res.success) {
                showToast('✅ ' + ((res.data && res.data.message) || 'Отзыв сохранён!'));
                // Трекинг прочтения
                const fdLog = new FormData();
                fdLog.append('action', 'csc_mark_read');
                fdLog.append('type', 'customer');
                fdLog.append('id', currentReviewPhone);
                fdLog.append('order_number', currentReviewOrder);
                fdLog.append('_wpnonce', NONCE);
                fetch(AJAX_URL, { method: 'POST', body: fdLog }).catch(() => {});
                closeReviewModal();
                document.getElementById('csc-login-form').dispatchEvent(new Event('submit'));
            } else {
                showToast('❌ ' + ((res.data && res.data.message) || 'Ошибка отправки отзыва'), 'error');
            }
        } catch (e) {
            showToast('Ошибка обработки ответа сервера', 'error');
        }
    };

    xhr.onerror = function() {
        progressEl.style.display = 'none';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Отправить';
        showToast('Ошибка сети. Попробуйте позже.', 'error');
    };

    xhr.send(fd);
});

}); // конец DOMContentLoaded
</script>
<?php
    return ob_get_clean();
}