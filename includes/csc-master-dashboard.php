<?php
/**
* Файл: csc-master-dashboard.php
* Описание: Личный кабинет мастера. Современный дизайн, загрузка отзывов, звуковые уведомления.
* Версия: 6.1 (объединение 5.0 + 6.0 с оптимизацией)
*
* Архитектура:
* - CSS: assets/css/master-dashboard.css
* - JS:  assets/js/master-dashboard.js
* - PHP: авторизация + HTML-каркас + REST endpoints
*
* ВОЗМОЖНОСТИ:
* - Фото мастера в аватаре шапки (5.0)
* - Виджет уведомлений (колокольчик с бейджем + панель) (5.0)
* - Карта зоны обслуживания (точка выезда + радиус 3-20 км) (6.0)
* - REST endpoint /master/update-location для редактирования зоны (6.0)
* - Загрузка файлов отчёта о выполнении (5.0)
* - Прокси для безопасной отдачи файлов (5.0)
* - Endpoints уведомлений мастера (5.0)
*/
if (!defined('ABSPATH')) exit;

// ============================================================
// ПОДКЛЮЧЕНИЕ ЯДРА УВЕДОМЛЕНИЙ
// ============================================================
require_once plugin_dir_path(__FILE__) . 'csc-notifications.php';

// ============================================================
// 1. РЕГИСТРАЦИЯ ASSETS (CSS + JS)
// ============================================================
if (!function_exists('csc_register_master_dashboard_assets')) {
    function csc_register_master_dashboard_assets() {
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $plugin_dir = plugin_dir_path(dirname(__FILE__));

        // CSS
        $css_path = $plugin_dir . 'assets/css/master-dashboard.css';
        $css_url = $plugin_url . 'assets/css/master-dashboard.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '6.1';
        wp_register_style('csc-master-dashboard', $css_url, [], $css_version);

        // JS
        $js_path = $plugin_dir . 'assets/js/master-dashboard.js';
        $js_url = $plugin_url . 'assets/js/master-dashboard.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : '6.1';
        wp_register_script('csc-master-dashboard', $js_url, [], $js_version, true);
    }
}
add_action('init', 'csc_register_master_dashboard_assets');

// ============================================================
// 2. АВТОРИЗАЦИЯ И РЕНДЕР СТРАНИЦЫ
// ============================================================
add_action('template_redirect', 'csc_render_master_dashboard');

if (!function_exists('csc_render_master_dashboard')) {
    function csc_render_master_dashboard() {
        if (!isset($_GET['csc_master_dashboard']) || empty($_GET['mid']) || empty($_GET['token'])) return;

        $mid = sanitize_text_field($_GET['mid']);
        $token = sanitize_text_field($_GET['token']);

        // ИСПРАВЛЕНО (защита ЛК мастера): лимит попыток входа по токену.
        // Функция csc_check_master_login_rate_limit была определена, но не вызывалась —
        // подбор 32-символьного токена ничем не ограничивался.
        if (function_exists('csc_check_master_login_rate_limit')) {
            $rl = csc_check_master_login_rate_limit($mid);
            if (!$rl['allowed']) {
                nocache_headers();
                wp_die(sprintf(
                    __('Слишком много неудачных попыток входа. Повторите через %d минут.', 'csc'),
                    max(1, (int) ceil($rl['retry_after'] / MINUTE_IN_SECONDS))
                ), __('Ошибка доступа', 'csc'), ['response' => 429]);
            }
        }

        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
        if (!isset($masters[$mid])) {
        wp_die(__('Доступ запрещен. Мастер не найден.', 'csc'), __('Ошибка доступа', 'csc'), ['response' => 403]);
        }
        $stored_token = isset($masters[$mid]['dashboard_token']) ? (string)$masters[$mid]['dashboard_token'] : '';
        // ИСПРАВЛЕНО: три легитимных пути входа, все — без постоянного токена в URL:
        // 1) одноразовый токен Telegram (csc_tg_get_dashboard_link);
        // 2) одноразовый входной креденшел админки/рассылок (csc_issue_master_login_token);
        // 3) legacy-фолбэк: постоянный токен из хранилища (для старых ссылок до миграции).
        $is_valid_token = false;
        // 1. Проверяем одноразовый токен (сгенерирован через csc_tg_get_dashboard_link)
        if (function_exists('csc_tg_verify_dashboard_token') && csc_tg_verify_dashboard_token($mid, $token)) {
        $is_valid_token = true;
        }
        // 2. Одноразовый креденшел, выданный csc_issue_master_login_token()
        elseif (function_exists('csc_verify_master_login_token') && csc_verify_master_login_token($token) === $mid) {
        $is_valid_token = true;
        }
        // 3. Фолбэк: сверка постоянного токена (хэш SHA-256 или legacy-открытый)
        elseif ($stored_token !== '' && strlen($stored_token) >= 16
            && function_exists('csc_verify_token') && csc_verify_token($token, $stored_token)) {
        $is_valid_token = true;
        }
        // ИСПРАВЛЕНО (защита ЛК мастера): учёт неудачной попытки входа (подбор токена).
        // Счётчик инкрементируется в csc_check_master_login_rate_limit при каждом
        // запросе входа; при успехе ниже сбрасывается полностью.
        if (!$is_valid_token) {
            wp_die(__('Доступ запрещен. Ссылка недействительна или истекла.', 'csc'), __('Ошибка доступа', 'csc'), ['response' => 403]);
        }

        // Успешный вход — сброс счётчика попыток подбора токена
        if (function_exists('csc_reset_master_login_rate_limit')) {
            csc_reset_master_login_rate_limit($mid);
        }

        // Проверка статуса модерации
        $master_status = $masters[$mid]['status'] ?? 'pending';
        $master_name = $masters[$mid]['name'] ?? 'Мастер';
        $is_online = $masters[$mid]['is_online'] ?? false;
        $penalties_total = $masters[$mid]['penalties_total'] ?? 0;
        $penalties_amount = $masters[$mid]['penalties_amount'] ?? 0;

        // ИСПРАВЛЕНО (защита ЛК мастера): в страницу/JS больше НЕ попадает хэш
        // постоянного токена. Токен из URL мог быть ОДНОКРАТНЫМ (Telegram/админ-ссылка,
        // transient уже удалён при сверке) или legacy-постоянным. Для авторизации
        // REST/AJAX-вызовов страницы нормализуем его к «живому» открытому токену:
        // если stored — хэш, генерируется новый постоянный токен этой сессии (в
        // хранилище сохраняется только его хэш); если stored — legacy открытый,
        // он остаётся working значением (ленивая миграция в хэш — при первой сверке).
        $session_token = '';
        if (function_exists('csc_normalize_master_session_token')) {
            $session_token = csc_normalize_master_session_token($mid, $masters);
        } elseif ($stored_token !== '' && !csc_is_hashed_token($stored_token)) {
            $session_token = $stored_token; // фолбэк без ядра: legacy-открытый токен
        }
        if ($session_token === '') {
            // Мастер вошёл по одноразовому креденшелу, но постоянного токена в
            // хранилище нет/он пуст — создаём новый (хранится только хэш).
            $fresh = wp_generate_password(32, false);
            $masters[$mid]['dashboard_token'] = function_exists('csc_hash_token')
                ? csc_hash_token($fresh)
                : $fresh;
            update_option('csc_masters_list', $masters, false);
            if (function_exists('csc_cache_delete_masters')) {
                csc_cache_delete_masters();
            }
            $session_token = $fresh;
        }
        $site_url = esc_url(home_url());
        $ajax_nonce = wp_create_nonce('csc_cabinet_nonce');

        // Данные мастера для UI
        $master_photo = !empty($masters[$mid]['photo']) ? esc_url($masters[$mid]['photo']) : '';
        $service_address = $masters[$mid]['service_address'] ?? '';
        $service_lat = isset($masters[$mid]['service_lat']) ? floatval($masters[$mid]['service_lat']) : 0;
        $service_lng = isset($masters[$mid]['service_lng']) ? floatval($masters[$mid]['service_lng']) : 0;
        $service_radius = isset($masters[$mid]['service_radius']) ? intval($masters[$mid]['service_radius']) : 10;

        wp_enqueue_style('csc-master-dashboard');
        wp_enqueue_script('csc-master-dashboard');

        // Проверка наличия кастомного звукового файла
        $sound_file_path = WP_PLUGIN_DIR . '/custom-service-calculator/assets/sounds/csc-notify.mp3';
        $sound_url = file_exists($sound_file_path) 
            ? plugins_url('custom-service-calculator/assets/sounds/csc-notify.mp3') 
            : '';

        wp_localize_script('csc-master-dashboard', 'CSC_MASTER_CONFIG', [
            'mid'           => $mid,
            'token'         => $session_token,
            'nonce'         => $ajax_nonce,
            'masterName'    => $master_name,
            'masterStatus'  => $master_status,
            'isOnline'      => $is_online,
            'penaltiesTotal'=> $penalties_total,
            'penaltiesAmount'=> $penalties_amount,
            'restUrl'       => esc_url_raw(rest_url('csc/v1/master/')),
            'ajaxUrl'       => esc_url_raw(admin_url('admin-ajax.php')),
            'siteUrl'       => $site_url,
            'vapidKey'      => esc_js(get_option('csc_vapid_public_key', '')),
            'photoUrl'       => $master_photo,
            'serviceAddress' => $service_address,
            'serviceLat'     => $service_lat,
            'serviceLng'     => $service_lng,
            'serviceRadius'  => $service_radius,
            'soundUrl'       => $sound_url, // <--- ДОБАВЛЕНО: URL звукового файла или пустая строка
            // i18n: локализованные подписи статусов для инлайнового JS (getStatusLabel)
            'statusLabels'   => function_exists('csc_status_labels_js') ? csc_status_labels_js() : [],
            // i18n: общий словарь строк интерфейса JS (cscI18n.t('key'))
            'i18n'           => function_exists('csc_js_strings_dashboard') ? csc_js_strings_dashboard() : [],
        ]);
        // window.CSC_I18N — то же содержимое, единое имя для всех скриптов страницы.
        wp_localize_script('csc-master-dashboard', 'CSC_I18N', function_exists('csc_js_strings_dashboard') ? csc_js_strings_dashboard() : []);

        nocache_headers();
        ?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Кабинет мастера | <?php echo esc_html($master_name); ?></title>
    
    <!-- Подключение Leaflet для карты зоны обслуживания -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <?php wp_head(); ?>
</head>
<body class="csc-dashboard-body">

<div class="csc-bg-decoration" aria-hidden="true">
    <div class="csc-bg-blob csc-bg-blob-1"></div>
    <div class="csc-bg-blob csc-bg-blob-2"></div>
    <div class="csc-bg-blob csc-bg-blob-3"></div>
</div>

<div class="csc-dashboard-wrapper">

    <!-- HEADER -->
    <header class="csc-header">
        <div class="csc-header-left">
            <!-- Аватар с фото мастера -->
            <div class="csc-avatar" id="csc-avatar">
                <?php if (!empty($master_photo)): ?>
                    <img src="<?php echo esc_url($master_photo); ?>" alt="<?php echo esc_attr($master_name); ?>" class="csc-avatar-photo" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">
                <?php else: ?>
                    <span id="csc-avatar-letter"><?php echo esc_html(mb_strtoupper(mb_substr($master_name, 0, 1))); ?></span>
                <?php endif; ?>
                <span class="csc-status-dot" id="csc-status-dot"></span>
            </div>
            
            <div class="csc-header-info">
                <h1 class="csc-header-title">Привет, <?php echo esc_html($master_name); ?>! 👋</h1>
                <p class="csc-header-subtitle" id="csc-status-text">
                    <?php if ($master_status === 'pending'): ?>
                        ⏳ Заявка на модерации
                    <?php elseif ($master_status === 'blocked'): ?>
                        🚫 Аккаунт заблокирован
                    <?php elseif ($master_status === 'rejected'): ?>
                        ❌ Заявка отклонена
                    <?php elseif ($is_online): ?>
                        🟢 Активен - принимаете заказы
                    <?php else: ?>
                        ⚪ Неактивен
                    <?php endif; ?>
                </p>
            </div>
        </div>
        
        <div class="csc-header-right">
            <!-- Переключатель активности -->
            <?php if ($master_status === 'active' || $master_status === 'inactive'): ?>
            <div class="csc-activity-toggle" id="csc-activity-toggle">
                <button type="button" class="csc-toggle-btn <?php echo $is_online ? 'active' : ''; ?>" id="csc-toggle-activity" aria-label="Переключить статус активности">
                    <span class="csc-toggle-knob"></span>
                    <span class="csc-toggle-label"><?php echo $is_online ? 'Активен' : 'Неактивен'; ?></span>
                </button>
            </div>
            <?php endif; ?>
            
            <!-- Кнопка уведомлений -->
            <button type="button" class="csc-icon-btn csc-notifications-btn" id="csc-notifications-toggle" aria-label="Уведомления" title="Уведомления" style="position:relative;">
                <span class="csc-icon">💬</span>
                <span class="csc-notif-badge" id="csc-notif-badge" hidden style="position:absolute; top:-4px; right:-4px; min-width:18px; height:18px; padding:0 4px; border-radius:50%; background:#ef4444; color:#fff; font-size:10px; font-weight:700; display:flex; align-items:center; justify-content:center;">0</span>
            </button>
            
            <button type="button" class="csc-icon-btn" id="csc-sound-toggle" aria-label="Звуковые уведомления" title="Звуковые уведомления">
                <span class="csc-icon">🔔</span>
            </button>
            
            <button type="button" class="csc-icon-btn" id="csc-theme-toggle" aria-label="Переключить тему" title="Переключить тему">
                <span class="csc-icon" id="csc-theme-icon">🌙</span>
            </button>
            
            <a href="<?php echo $site_url; ?>" class="csc-icon-btn" aria-label="На сайт" title="На сайт">
                <span class="csc-icon">🏠</span>
            </a>
        </div>
    </header>
	    <!-- ПАНЕЛЬ УВЕДОМЛЕНИЙ (FIXED OVERLAY) -->
        <div class="csc-notifications-panel" id="csc-notifications-panel" hidden>
            <div class="csc-notif-panel-header">
                <h3 class="csc-notif-panel-title">🔔 Уведомления</h3>
                <div class="csc-notif-panel-actions">
                    <button type="button" class="csc-notif-mark-read" id="csc-mark-all-read">✓ Прочитать</button>
                    <button type="button" class="csc-notif-clear-all" id="csc-notif-clear-all" title="Очистить все">🗑</button>
                </div>
            </div>
            <div class="csc-notif-list" id="csc-notif-list">
                <div class="csc-notif-loading">Загрузка...</div>
            </div>
        </div>

    <!-- Предупреждение о блокировке -->
    <?php if ($master_status === 'blocked'): ?>
    <div class="csc-alert csc-alert-error">
        <span class="csc-alert-icon">🚫</span>
        <div class="csc-alert-content">
            <strong>Аккаунт заблокирован</strong>
            <p>У вас накоплено штрафов: <?php echo esc_html($penalties_total); ?> на сумму <?php echo number_format($penalties_amount, 0, '.', ' '); ?> ₽. Обратитесь к администратору для разблокировки.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Предупреждение о модерации -->
    <?php if ($master_status === 'pending'): ?>
    <div class="csc-alert csc-alert-info">
        <span class="csc-alert-icon">⏳</span>
        <div class="csc-alert-content">
            <strong>Заявка на рассмотрении</strong>
            <p>Администрация проверяет ваши данные. Вы получите уведомление на email после одобрения.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Предупреждение об отклонении -->
    <?php if ($master_status === 'rejected'): ?>
    <div class="csc-alert csc-alert-error">
        <span class="csc-alert-icon">❌</span>
        <div class="csc-alert-content">
            <strong>Заявка отклонена</strong>
            <p><?php echo esc_html($masters[$mid]['rejection_reason'] ?? 'Причина не указана'); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Индикатор штрафов -->
    <?php if ($penalties_total > 0 && ($master_status === 'active' || $master_status === 'inactive')): ?>
    <div class="csc-alert csc-alert-warning">
        <span class="csc-alert-icon">⚠️</span>
        <div class="csc-alert-content">
            <strong>Штрафы: <?php echo esc_html($penalties_total); ?></strong>
            <p>Сумма: <?php echo number_format($penalties_amount, 0, '.', ' '); ?> ₽. При превышении лимита аккаунт будет заблокирован.</p>
        </div>
    </div>
    <?php endif; ?>

<!-- ============================================================
    СЕКЦИЯ ЗОНЫ ОБСЛУЖИВАНИЯ (КАРТА + РАДИУС)
    Доступна только активным мастерам
    ============================================================ -->
<?php if ($master_status === 'active' || $master_status === 'inactive'): ?>
<section class="csc-zone-section" id="csc-zone-section" style="margin-bottom:24px;">
    <div style="background: var(--csc-bg-glass); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); border: 1px solid var(--csc-border); border-radius: var(--csc-radius-lg); padding: 20px; box-shadow: var(--csc-shadow-sm);">
        <h2 style="margin: 0 0 16px 0; font-size: 18px; font-weight: 700; color: var(--csc-text-primary);">📍 Зона обслуживания</h2>
        <p style="margin: 0 0 12px 0; font-size: 13px; color: var(--csc-text-secondary); line-height: 1.5;">
            Перетащите маркер на карте, чтобы указать точку выезда.
            Заказы вне радиуса не будут вам назначаться.
            <?php if ($service_address): ?>
                <br><strong style="color: var(--csc-text-primary);">Текущий адрес:</strong> <?php echo esc_html($service_address); ?>
            <?php endif; ?>
        </p>
        <div id="csc-service-map" style="width: 100%; height: 300px; border-radius: var(--csc-radius-md); margin-bottom: 16px; background: var(--csc-bg-tertiary); border: 1px solid var(--csc-border); overflow: hidden;"></div>
        <div class="csc-zone-controls" style="display: flex; flex-direction: column; gap: 12px;">
            <label for="csc-service-radius" style="font-size: 14px; font-weight: 600; color: var(--csc-text-primary);">
                Радиус обслуживания: <span id="csc-radius-value" style="color: var(--csc-accent); font-variant-numeric: tabular-nums;"><?php echo esc_html($service_radius); ?></span> км
            </label>
            <input type="range" id="csc-service-radius" class="csc-range-input" min="1" max="3" value="<?php echo esc_attr($service_radius); ?>" step="1" style="width: 100%;">
            <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--csc-text-tertiary); font-weight: 500; text-transform: uppercase; letter-spacing: 0.03em;">
                <span>Мин: 1 км</span>
                <span>Макс: 3 км</span>
            </div>
            <button type="button" class="csc-btn csc-btn-primary" id="csc-save-zone-btn" style="width: auto; padding: 10px 24px; align-self: flex-start;">
                💾 Сохранить зону
            </button>
        </div>
    </div>
</section>
<?php endif; ?>

    <!-- СТАТИСТИКА МАСТЕРА -->
    <section class="csc-stats" id="csc-stats-section">
        <div class="csc-stat-card csc-stat-pending">
            <div class="csc-stat-icon">⏳</div>
            <div class="csc-stat-info">
                <span class="csc-stat-label">Ожидают</span>
                <span class="csc-stat-value" id="csc-stat-pending">0</span>
            </div>
        </div>
        <div class="csc-stat-card csc-stat-active">
            <div class="csc-stat-icon">🔧</div>
            <div class="csc-stat-info">
                <span class="csc-stat-label">В работе</span>
                <span class="csc-stat-value" id="csc-stat-active">0</span>
            </div>
        </div>
        <div class="csc-stat-card csc-stat-done">
            <div class="csc-stat-icon">✅</div>
            <div class="csc-stat-info">
                <span class="csc-stat-label">Выполнено</span>
                <span class="csc-stat-value" id="csc-stat-done">0</span>
            </div>
        </div>
        <div class="csc-stat-card csc-stat-rating">
            <div class="csc-stat-icon">⭐</div>
            <div class="csc-stat-info">
                <span class="csc-stat-label">Рейтинг</span>
                <span class="csc-stat-value" id="csc-stat-rating">—</span>
            </div>
        </div>
    </section>

    <!-- НАВИГАЦИЯ ПО ВКЛАДКАМ -->
    <nav class="csc-tabs">
        <button class="csc-tab-btn active" data-tab="waiting">
            <span class="csc-tab-icon">⏳</span>
            <span class="csc-tab-label">Ожидают</span>
            <span class="csc-tab-badge" id="csc-badge-waiting" hidden>0</span>
        </button>
        <button class="csc-tab-btn" data-tab="active">
            <span class="csc-tab-icon">🔧</span>
            <span class="csc-tab-label">В работе</span>
            <span class="csc-tab-badge" id="csc-badge-active" hidden>0</span>
        </button>
        <button class="csc-tab-btn" data-tab="completed">
            <span class="csc-tab-icon">✅</span>
            <span class="csc-tab-label">Выполнено</span>
            <span class="csc-tab-badge" id="csc-badge-completed" hidden>0</span>
        </button>
        <button class="csc-tab-btn" data-tab="cancelled">
            <span class="csc-tab-icon">❌</span>
            <span class="csc-tab-label">Отменено</span>
            <span class="csc-tab-badge" id="csc-badge-cancelled" hidden>0</span>
        </button>
        <!-- БЛОК 2а: вкладка Баланс (перед кнопкой Отзывы) -->
        <button class="csc-tab-btn" data-tab="balance">
            <span class="csc-tab-icon">💰</span>
            <span class="csc-tab-label">Баланс</span>
            <span class="csc-tab-badge" id="csc-badge-balance" hidden>0</span>
        </button>
        <button class="csc-tab-btn" data-tab="reviews">
            <span class="csc-tab-icon">⭐</span>
            <span class="csc-tab-label">Отзывы</span>
            <span class="csc-tab-badge" id="csc-badge-reviews" hidden>0</span>
        </button>
    </nav>

    <!-- ОСНОВНОЙ КОНТЕНТ: ЗАКАЗЫ, ОТЗЫВЫ, ПУСТОЕ СОСТОЯНИЕ -->
    <main class="csc-content">
        <div class="csc-loader" id="csc-loader">
            <div class="csc-loader-spinner"></div>
            <span>Загрузка...</span>
        </div>

        <div class="csc-empty" id="csc-empty" hidden>
            <div class="csc-empty-icon" id="csc-empty-icon">📭</div>
            <h3 class="csc-empty-title" id="csc-empty-title">Пока ничего нет</h3>
            <p class="csc-empty-text" id="csc-empty-text">Здесь появятся ваши заказы</p>
        </div>

        <div class="csc-orders-list" id="csc-orders-list" hidden></div>
        <div class="csc-reviews-list" id="csc-reviews-list" hidden></div>

        <!-- БЛОК 2б: секция баланса -->
        <div class="csc-balance-section" id="csc-balance-section" hidden>
            <!-- Сводка -->
            <div class="csc-balance-summary" id="csc-balance-summary"></div>
            <!-- Периоды -->
            <div class="csc-balance-periods" id="csc-balance-periods"></div>
            <!-- Детали -->
            <div class="csc-balance-details" id="csc-balance-details"></div>
            <!-- История транзакций -->
            <div class="csc-balance-history" id="csc-balance-history"></div>
        </div>
    </main>

    <!-- КОНТЕЙНЕР ДЛЯ ТОСТОВ (УВЕДОМЛЕНИЙ) -->
    <div class="csc-toast-container" id="csc-toast-container"></div>

    <!-- МОДАЛЬНОЕ ОКНО ДЕТАЛЕЙ ЗАКАЗА -->
    <div class="csc-modal" id="csc-order-modal" hidden>
        <div class="csc-modal-backdrop"></div>
        <div class="csc-modal-content">
            <div class="csc-modal-header">
                <h2 class="csc-modal-title">Заказ #<span id="csc-modal-order-number"></span></h2>
                <button type="button" class="csc-modal-close" id="csc-modal-close" aria-label="Закрыть">✕</button>
            </div>
            <div class="csc-modal-body" id="csc-modal-body"></div>
            <div class="csc-modal-footer" id="csc-modal-footer"></div>
        </div>
    </div>

    <!-- FAB (плавающая кнопка) для новых входящих заявок -->
    <button class="csc-fab" id="csc-fab" hidden aria-label="Новый заказ">
        <span class="csc-fab-icon">🆕</span>
        <span class="csc-fab-badge" id="csc-fab-badge">1</span>
    </button>

</div>

<?php wp_footer(); ?>
<!-- LIGHTBOX ДЛЯ ФОТО ОТЗЫВОВ -->
<style>
.csc-lightbox-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.9); z-index: 999999; display: none; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s ease; }
.csc-lightbox-overlay.active { display: flex; opacity: 1; }
.csc-lightbox-content { position: relative; max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.csc-lightbox-img { max-width: 90vw; max-height: 80vh; object-fit: contain; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
.csc-lightbox-close, .csc-lightbox-prev, .csc-lightbox-next { position: absolute; background: rgba(255,255,255,0.2); border: none; color: white; font-size: 24px; width: 48px; height: 48px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.csc-lightbox-close { top: 20px; right: 20px; font-size: 32px; }
.csc-lightbox-prev { left: 20px; top: 50%; transform: translateY(-50%); }
.csc-lightbox-next { right: 20px; top: 50%; transform: translateY(-50%); }
.csc-lightbox-counter { color: white; margin-top: 15px; font-size: 14px; font-weight: 600; background: rgba(0,0,0,0.5); padding: 4px 12px; border-radius: 12px; }
.csc-review-photo-thumb { width: 60px; height: 60px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 2px solid var(--csc-border); margin: 4px; }
.csc-notifications-client-btn{position:fixed;bottom:24px;right:24px;width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--csc-primary),var(--csc-primary-dark));color:#fff;border:none;font-size:24px;cursor:pointer;box-shadow:0 8px 24px rgba(59,130,246,.4);z-index:1500;transition:all .2s ease;display:flex;align-items:center;justify-content:center}
.csc-notifications-client-btn:hover{transform:scale(1.08);box-shadow:0 10px 30px rgba(59,130,246,.5)}
.csc-notifications-client-btn:active{transform:scale(.95)}
.csc-notif-icon{font-size:24px;line-height:1}
.csc-notif-badge{position:absolute;top:-4px;right:-4px;min-width:22px;height:22px;padding:0 6px;background:#ef4444;color:#fff;font-size:11px;font-weight:700;border-radius:11px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 2px 6px rgba(239,68,68,.5);animation:csc-client-badge-pulse 2s ease-in-out infinite}
@keyframes csc-client-badge-pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.2)}}
.csc-client-notif-modal{position:fixed;bottom:90px;right:24px;width:360px;max-width:calc(100vw - 40px);max-height:70vh;background:var(--csc-card);border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.2);z-index:1600;display:none;flex-direction:column;overflow:hidden;border:1px solid var(--csc-border)}
.csc-client-notif-modal.active{display:flex;animation:csc-client-modal-in .25s cubic-bezier(.34,1.56,.64,1)}
@keyframes csc-client-modal-in{from{opacity:0;transform:translateY(20px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}
.csc-client-notif-header{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--csc-border);background:var(--csc-bg)}
.csc-client-notif-header h3{margin:0;font-size:16px;font-weight:700;color:var(--csc-text)}
.csc-client-notif-actions{display:flex;gap:8px;align-items:center}
.csc-client-notif-mark-read{background:0 0;border:none;color:var(--csc-primary);font-size:12px;font-weight:600;cursor:pointer;padding:6px 10px;border-radius:6px;transition:background .15s}
.csc-client-notif-mark-read:hover{background:rgba(59,130,246,.1)}
.csc-client-notif-close{background:0 0;border:none;font-size:18px;cursor:pointer;color:var(--csc-text);line-height:1;padding:4px 8px;border-radius:6px;transition:background .15s}
.csc-client-notif-close:hover{background:var(--csc-border)}
.csc-client-notif-list{overflow-y:auto;flex:1}
.csc-client-notif-item{position:relative;padding:12px 16px;border-bottom:1px solid var(--csc-border);cursor:pointer;transition:background .15s;display:flex;gap:10px}
.csc-client-notif-item:hover{background:var(--csc-bg)}
.csc-client-notif-item.unread{background:rgba(59,130,246,.05)}
.csc-client-notif-item.unread::before{content:'';position:absolute;left:8px;top:50%;transform:translateY(-50%);width:6px;height:6px;border-radius:50%;background:var(--csc-primary)}
.csc-client-notif-icon-big{font-size:22px;flex-shrink:0}
.csc-client-notif-body{flex:1;min-width:0}
.csc-client-notif-title{font-size:13px;font-weight:600;color:var(--csc-text);margin-bottom:2px}
.csc-client-notif-msg{font-size:12px;color:var(--csc-text);opacity:.8;line-height:1.4;word-break:break-word}
.csc-client-notif-time{font-size:11px;color:var(--csc-text);opacity:.5;margin-top:4px}
.csc-client-notif-empty{padding:40px 20px;text-align:center;color:var(--csc-text);opacity:.5;font-size:13px}
@media (max-width:480px){.csc-notifications-client-btn{bottom:16px;right:16px;width:52px;height:52px}.csc-client-notif-modal{bottom:76px;right:12px;left:12px;width:auto;max-width:none}}
</style>
<div id="csc-lightbox" class="csc-lightbox-overlay">
    <div class="csc-lightbox-backdrop" style="position:absolute;top:0;left:0;width:100%;height:100%;"></div>
    <div class="csc-lightbox-content">
        <button class="csc-lightbox-close">&times;</button>
        <button class="csc-lightbox-prev">&#10094;</button>
        <button class="csc-lightbox-next">&#10095;</button>
        <img src="" class="csc-lightbox-img" alt="Review photo">
        <div class="csc-lightbox-counter"></div>
    </div>
</div>
<script>
window.CSC_Lightbox = {
    images: [], currentIndex: 0, overlay: null, img: null, counter: null,
    init: function() {
        if (this.overlay) return;
        this.overlay = document.getElementById('csc-lightbox');
        this.img = this.overlay.querySelector('.csc-lightbox-img');
        this.counter = this.overlay.querySelector('.csc-lightbox-counter');
        this.overlay.querySelector('.csc-lightbox-close').addEventListener('click', () => this.close());
        this.overlay.querySelector('.csc-lightbox-backdrop').addEventListener('click', () => this.close());
        this.overlay.querySelector('.csc-lightbox-prev').addEventListener('click', (e) => { e.stopPropagation(); this.prev(); });
        this.overlay.querySelector('.csc-lightbox-next').addEventListener('click', (e) => { e.stopPropagation(); this.next(); });
        document.addEventListener('keydown', (e) => {
            if (!this.overlay.classList.contains('active')) return;
            if (e.key === 'Escape') this.close();
            if (e.key === 'ArrowLeft') this.prev();
            if (e.key === 'ArrowRight') this.next();
        });
    },
    open: function(images, startIndex = 0) {
        this.init(); this.images = images; this.currentIndex = startIndex; this.updateView();
        this.overlay.style.display = 'flex'; setTimeout(() => this.overlay.classList.add('active'), 10);
    },
    close: function() { this.overlay.classList.remove('active'); setTimeout(() => { this.overlay.style.display = 'none'; }, 300); },
    prev: function() { this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length; this.updateView(); },
    next: function() { this.currentIndex = (this.currentIndex + 1) % this.images.length; this.updateView(); },
    updateView: function() {
        this.img.src = this.images[this.currentIndex];
        this.counter.textContent = (this.currentIndex + 1) + ' / ' + this.images.length;
        this.overlay.querySelector('.csc-lightbox-prev').style.display = this.images.length > 1 ? 'flex' : 'none';
        this.overlay.querySelector('.csc-lightbox-next').style.display = this.images.length > 1 ? 'flex' : 'none';
    }
};
</script>
</body>
</html>
        <?php
        exit;
    }
}
// ============================================================
// 3. REST API ENDPOINTS
// ============================================================
add_action('rest_api_init', 'csc_register_master_routes');

if (!function_exists('csc_register_master_routes')) {
    function csc_register_master_routes() {
        /**
         * Обработчик эндпоинта: финансовый отчёт мастера.
         * GET /wp-json/csc/v1/master/finance?days=30
         */
        if (!function_exists('csc_handle_master_finance')) {
            function csc_handle_master_finance($request) {
                $mid   = sanitize_text_field($request->get_param('mid'));
                $token = sanitize_text_field($request->get_param('token'));
                $days  = intval($request->get_param('days') ?? 30);

                // Валидация периода
                $allowed_days = [7, 30, 90, 365, 0];
                if (!in_array($days, $allowed_days, true)) {
                    $days = 30;
                }

                if (empty($mid) || empty($token)) {
                    return new WP_Error('missing_auth', 'Не передан mid или token', ['status' => 400]);
                }

                // ИСПРАВЛЕНО (SHA-256 миграция): проверка через единую функцию авторизации.
                // Прежний hash_equals по stored-значению ломался после хэширования токенов
                // и не поддерживал одноразовые входные креденшелы.
                $auth = csc_verify_master_auth($request);
                if (is_wp_error($auth)) {
                    return $auth;
                }
                $master = $auth['masters'][$mid];

                if (!function_exists('csc_finance_get_master_report')) {
                    return new WP_Error('module_missing', 'Финансовый модуль не подключён', ['status' => 500]);
                }

                $report = csc_finance_get_master_report($mid, $days);
                if (isset($report['error'])) {
                    return new WP_Error('report_error', $report['error'], ['status' => 500]);
                }

                // Добавляем комиссию мастера для отображения в UI
                $report['commission'] = [
                    'type'  => $master['comm_type'] ?? 'percent',
                    'value' => floatval($master['comm_val'] ?? 0),
                ];
                $report['master_name'] = $master['name'] ?? '';

                return new WP_REST_Response([
                    'success' => true,
                    'data'    => $report,
                ], 200);
            }
        }

        /**
         * Обработчик: очистить все уведомления мастера
         * POST /wp-json/csc/v1/master/notifications/clear
         */
        if (!function_exists('csc_handle_clear_master_notifications')) {
            function csc_handle_clear_master_notifications($request) {
                $auth = csc_verify_master_auth($request);
                if (is_wp_error($auth)) return $auth;
                $mid = $auth['mid'];

                $option_key = 'csc_in_cabinet_master_' . $mid;
                update_option($option_key, [], false);

                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'Все уведомления удалены',
                ], 200);
            }
        }

        // ============================================================
        // РЕГИСТРАЦИЯ МАРШРУТОВ
        // ============================================================

        // Получение списка заказов мастера
        register_rest_route('csc/v1', '/master/orders', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_master_orders',
            'permission_callback' => '__return_true',
        ]);

        // Действия мастера над заказом (accept, complete, start)
        register_rest_route('csc/v1', '/master/action', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_master_action',
            'permission_callback' => '__return_true',
        ]);

        // Получение отзывов о мастере
        register_rest_route('csc/v1', '/master/reviews', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_master_reviews',
            'permission_callback' => '__return_true',
        ]);

        // Получение статистики мастера
        register_rest_route('csc/v1', '/master/stats', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_master_stats',
            'permission_callback' => '__return_true',
        ]);

        // Загрузка файлов отчёта о выполнении
        register_rest_route('csc/v1', '/master/upload-completion', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_upload_completion',
            'permission_callback' => '__return_true',
        ]);

        // Прокси для безопасной отдачи файлов
        register_rest_route('csc/v1', '/master/file-proxy', [
            'methods'             => 'GET',
            'callback'            => 'csc_handle_file_proxy',
            'permission_callback' => '__return_true',
        ]);

        // Редактирование зоны обслуживания (маркер + радиус)
        register_rest_route('csc/v1', '/master/update-location', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_update_location',
            'permission_callback' => '__return_true',
        ]);

        // Список уведомлений мастера
        register_rest_route('csc/v1', '/master/notifications', [
            'methods'             => ['GET', 'POST'],
            'callback'            => 'csc_handle_master_notifications',
            'permission_callback' => '__return_true',
        ]);

        // Пометить все уведомления как прочитанные
        register_rest_route('csc/v1', '/master/notifications/mark-read', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_mark_master_notifications_read',
            'permission_callback' => '__return_true',
        ]);

        // Удалить одно уведомление
        register_rest_route('csc/v1', '/master/notifications/delete', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_delete_master_notification',
            'permission_callback' => '__return_true',
        ]);

        // Очистить все уведомления мастера
        register_rest_route('csc/v1', '/master/notifications/clear', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_clear_master_notifications',
            'permission_callback' => '__return_true',
        ]);

        // Баланс мастера (финансовая сводка + история транзакций)
        register_rest_route('csc/v1', '/master/balance', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_master_balance',
            'permission_callback' => '__return_true',
        ]);

        // Финансовый отчёт мастера
        register_rest_route('csc/v1', '/master/finance', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_master_finance',
            'permission_callback' => '__return_true',
        ]);
    }
}

// ============================================================
// 4. ОБЩАЯ ФУНКЦИЯ ПРОВЕРКИ АВТОРИЗАЦИИ МАСТЕРА
// ============================================================
if (!function_exists('csc_verify_master_auth')) {
    function csc_verify_master_auth($request) {
        $mid = sanitize_text_field($request->get_param('mid'));

        // ИСПРАВЛЕНО: Токен сначала ищем в заголовке (для GET), затем в параметрах (для POST)
        $token_header = $request->get_header('X-CSC-Token');
        if (!empty($token_header)) {
            $token = sanitize_text_field($token_header);
        } else {
            $token = sanitize_text_field($request->get_param('token'));
        }

        if (empty($mid) || empty($token)) {
            return new WP_Error('unauthorized', 'Missing credentials', ['status' => 401]);
        }

        // ИСПРАВЛЕНО (защита ЛК мастера): лимит попыток подбора токена.
        // Тот же счётчик, что и на странице входа кабинета.
        if (function_exists('csc_check_master_login_rate_limit')) {
            $rl = csc_check_master_login_rate_limit($mid);
            if (!$rl['allowed']) {
                return new WP_Error('rate_limited', 'Слишком много неудачных попыток. Повторите позже.', ['status' => 429]);
            }
        }

        $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];

        // ИСПРАВЛЕНО 6.2: защита от пустого/некорректного списка мастеров
        if (empty($masters) || !is_array($masters)) {
            return new WP_Error('internal', 'Master list unavailable', ['status' => 500]);
        }

        if (!isset($masters[$mid])) {
            return new WP_Error('unauthorized', 'Master not found', ['status' => 401]);
        }

        $stored_token = isset($masters[$mid]['dashboard_token']) ? (string)$masters[$mid]['dashboard_token'] : '';

        if ($stored_token === '' || strlen($stored_token) < 16) {
            return new WP_Error('unauthorized', 'Invalid token configuration', ['status' => 401]);
        }

        // ИСПРАВЛЕНО (SHA-256 миграция): сверка через csc_verify_token — поддерживает
        // хэш stored-значения + ленивую миграцию legacy открытых токенов; hash_equals
        // напрямую по хэшу ломал бы все REST-вызовы после миграции.
        $is_valid = false;
        if (function_exists('csc_verify_token')) {
            $on_migrate = null;
            if (!csc_is_hashed_token($stored_token)) {
                $on_migrate = function ($new_hash) use (&$masters, $mid) {
                    $masters[$mid]['dashboard_token'] = $new_hash;
                    update_option('csc_masters_list', $masters, false);
                    if (function_exists('csc_cache_delete_masters')) {
                        csc_cache_delete_masters();
                    }
                };
            }
            $is_valid = csc_verify_token($token, $stored_token, $on_migrate);
        } else {
            $is_valid = hash_equals($stored_token, (string)$token);
        }

        // Дополнительный путь: одноразовый входной креденшел (csc_issue_master_login_token).
        if (!$is_valid && function_exists('csc_verify_master_login_token')) {
            $login_mid = csc_verify_master_login_token($token);
            if ($login_mid === $mid) {
                $is_valid = true;
            }
        }

        if (!$is_valid) {
            return new WP_Error('unauthorized', 'Invalid credentials', ['status' => 401]);
        }

        // Успех — сброс счётчика попыток
        if (function_exists('csc_reset_master_login_rate_limit')) {
            csc_reset_master_login_rate_limit($mid);
        }

        return ['mid' => $mid, 'masters' => $masters];
    }
}

// ============================================================
// 5. ENDPOINT: ПОЛУЧЕНИЕ ЗАКАЗОВ МАСТЕРА
// ============================================================
if (!function_exists('csc_handle_master_orders')) {
    function csc_handle_master_orders($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        
        // ОПТИМИЗАЦИЯ (Кэш заказов): SQL-выборка заказов мастера по индексам
        // (idx_master_id / idx_current_candidate) вместо загрузки ВСЕГО списка
        // и PHP-фильтрации. Фолбэк на старый путь, если хелпер недоступен.
        if (function_exists('csc_get_orders_for_master')) {
            $my_orders = csc_get_orders_for_master($mid);
            $total_count = count($my_orders);
        } else {
            $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders(true) : get_option('csc_all_orders', []);
            $total_count = count($all_orders);

            // Расширенный фильтр: включаем все заказы, где мастер участвует
            $my_orders = array_filter($all_orders, function($o) use ($mid) {
                $orderMasterId    = $o['master_id'] ?? '';
                $currentCandidate = $o['current_candidate'] ?? '';
                $acceptedBy       = $o['accepted_by'] ?? '';

                // 1. Мастер назначен на заказ (выполненные, в работе, отменённые)
                if ($orderMasterId === $mid) {
                    return true;
                }

                // 2. Мастер принял заказ (accepted_by)
                if ($acceptedBy === $mid) {
                    return true;
                }

                // 3. Мастер является текущим кандидатом (ожидающие предложения)
                if ($currentCandidate === $mid) {
                    return true;
                }

                return false;
            });
        }

        // Сортировка: ожидание → в работе → выполнено → отменено, затем по дате
        uasort($my_orders, function($a, $b) {
            $status_order = [
                CSC_STATUS_SEARCHING => 0, 
                CSC_STATUS_PENDING => 0, 
                CSC_STATUS_AWAITING_PAYMENT => 0,
                CSC_STATUS_PROCESSING => 1, 
                CSC_STATUS_COMPLETED => 2, 
                CSC_STATUS_COMPLETED => 2,
                CSC_STATUS_CANCELLED => 3,
            ];
            $s_a = $status_order[$a['status'] ?? ''] ?? 99;
            $s_b = $status_order[$b['status'] ?? ''] ?? 99;
            
            if ($s_a === $s_b) {
                // Внутри одного статуса — новые сверху
                $date_a = $a['created_at'] ?? $a['date'] ?? '';
                $date_b = $b['created_at'] ?? $b['date'] ?? '';
                return strcmp($date_b, $date_a);
            }
            return $s_a - $s_b;
        });

        // Отладочное логирование (включите WP_DEBUG для просмотра)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            csc_log('Master: ' . $mid . ' | Total orders: ' . $total_count . ' | Filtered: ' . count($my_orders));
            foreach ($my_orders as $num => $o) {
                csc_log('#' . $num . ' status=' . ($o['status'] ?? '?') . ' master_id=' . ($o['master_id'] ?? '') . ' candidate=' . ($o['current_candidate'] ?? ''));
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['orders' => array_values($my_orders)],
        ], 200);
    }
}

// ============================================================
// 6. ENDPOINT: ДЕЙСТВИЯ МАСТЕРА НАД ЗАКАЗОМ
// ============================================================
if (!function_exists('csc_handle_master_action')) {
    function csc_handle_master_action($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        $action = sanitize_text_field($request->get_param('action'));
        $order_number = sanitize_text_field($request->get_param('order_number'));

        if (empty($action) || empty($order_number)) {
            return new WP_Error('bad_request', 'Missing action or order_number', ['status' => 400]);
        }

        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        if (!isset($all_orders[$order_number])) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $order = &$all_orders[$order_number];
        if (($order['master_id'] ?? '') !== $mid && ($order['current_candidate'] ?? '') !== $mid) {
            return new WP_Error('forbidden', 'Not your order', ['status' => 403]);
        }

        $old_status = $order['status'] ?? CSC_STATUS_PENDING;
        $customer_phone = function_exists('csc_normalize_phone')
            ? csc_normalize_phone($order['phone'] ?? '')
            : preg_replace('/[^0-9]/', '', $order['phone'] ?? '');

        // Обработка действий
        if ($action === 'accept') {
            $order['master_id'] = $mid;
            $order['status'] = CSC_STATUS_PROCESSING;
            $order['started_at'] = current_time('mysql');
            $order['routing_status'] = 'assigned';
            $order['current_candidate'] = null;
            $order['offer_time'] = null;
            $order['tg_message_id'] = null;
            do_action('csc_order_status_changed', $order_number, $old_status, CSC_STATUS_PROCESSING, 'master', $mid, '');
            
        } elseif ($action === 'complete') {
            $order['status'] = CSC_STATUS_COMPLETED;
            $order['completed_at'] = current_time('mysql');
            $order['routing_status'] = 'completed';
            do_action('csc_order_status_changed', $order_number, $old_status, CSC_STATUS_COMPLETED, 'master', $mid, '');
            
        } elseif ($action === 'start') {
            $order['status'] = CSC_STATUS_PROCESSING;
            $order['started_at'] = current_time('mysql');
            do_action('csc_order_status_changed', $order_number, $old_status, CSC_STATUS_PROCESSING, 'master', $mid, '');
            
        } elseif ($action === 'cancel') {
            $reason = sanitize_text_field($request->get_param('reason') ?? '');
            $order['status'] = CSC_STATUS_CANCELLED;
            $order['canceled_at'] = current_time('mysql');
            $order['cancel_reason'] = $reason;
            if (isset($order['routing_status'])) {
                $order['routing_status'] = 'canceled';
            }
            do_action('csc_order_status_changed', $order_number, $old_status, CSC_STATUS_CANCELLED, 'master', $mid, $reason);
            
        } else {
            return new WP_Error('bad_request', 'Unknown action', ['status' => 400]);
        }
        
        do_action('csc_master_completed_order', $order_number, $mid);

        // Сохранение заказа
        if (function_exists('csc_update_order')) {
            csc_update_order($order_number, $order);
            if (function_exists('csc_cache_delete_orders')) csc_cache_delete_orders();
        } else {
            update_option('csc_all_orders', $all_orders, false);
        }

        // Логирование истории статусов
        if (function_exists('csc_log_status_change') && $old_status !== $order['status']) {
            csc_log_status_change(
                $order_number,
                $old_status,
                $order['status'],
                'master',
                $mid,
                'Изменение статуса мастером: ' . $action
            );
        }

        // === ИНТЕГРАЦИЯ: ЛОГИРОВАНИЕ И РАССЫЛКА ===
        if (function_exists('csc_log_notification')) {
            csc_log_notification($order_number, 'master', $mid, 'web',
                "📦 Статус изменен мастером",
                "Заказ #$order_number переведен в статус \"{$order['status']}\".",
                ['old_status' => $old_status, 'new_status' => $order['status']]
            );
            csc_log_notification($order_number, 'customer', $customer_phone, 'web',
                "📌 Обновление заказа",
                "Ваш заказ #$order_number теперь в статусе: \"{$order['status']}\".",
                ['trigger' => 'master_action']
            );
        }

        // Push-уведомления клиенту
        if (function_exists('csc_send_web_push_notification')) {
            $subs = get_option("csc_push_subs_customer_{$customer_phone}", []);
            foreach ($subs as $sub) {
                csc_send_web_push_notification($sub, "📌 Заказ #$order_number",
                    "Статус изменен: {$order['status']}", $order_number, 'customer', $customer_phone);
            }
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Action completed',
            'data'    => ['order' => $order],
        ], 200);
    }
}

// ============================================================
// 7. ENDPOINT: ПОЛУЧЕНИЕ ОТЗЫВОВ МАСТЕРА
// ============================================================
if (!function_exists('csc_handle_master_reviews')) {
    function csc_handle_master_reviews($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);

        $reviews = [];
        $total_rating = 0;
        $rating_count = 0;

        foreach ($all_orders as $order) {
            if (($order['master_id'] ?? '') !== $mid) continue;
            if (empty($order['review']) || !is_array($order['review'])) continue;

            $rating = intval($order['review']['rating'] ?? 0);
            if ($rating < 1 || $rating > 5) continue;

            $reviews[] = [
                'order_number' => $order['order_number'] ?? '',
                'rating'       => $rating,
                'comment'      => $order['review']['comment'] ?? '',
                'date'         => $order['review']['date'] ?? $order['completed_at'] ?? $order['created_at'] ?? '',
                'customer'     => $order['name'] ?? 'Клиент',
                'total'        => floatval($order['total'] ?? 0),
                'services'     => $order['services_for_master'] ?? $order['services'] ?? [],
				'photos'       => $order['review']['photos'] ?? [],

            ];

            $total_rating += $rating;
            $rating_count++;
        }

        // Сортировка: новые сверху
        usort($reviews, function($a, $b) {
            return strtotime($b['date'] ?? '') - strtotime($a['date'] ?? '');
        });

        $avg_rating = $rating_count > 0 ? round($total_rating / $rating_count, 1) : 0;

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'reviews'      => $reviews,
                'avg_rating'   => $avg_rating,
                'total_count'  => count($reviews),
            ],
        ], 200);
    }
}

// ============================================================
// 8. ENDPOINT: СТАТИСТИКА МАСТЕРА
// ============================================================
if (!function_exists('csc_handle_master_stats')) {
    function csc_handle_master_stats($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);

        $stats = [
            'pending'       => 0,
            'active'        => 0,
            'completed'     => 0,
            'cancelled'     => 0,
            'total_revenue' => 0,
            'month_revenue' => 0,
            'avg_rating'    => 0,
        ];

        $this_month = date('Y-m');
        $total_rating = 0;
        $rating_count = 0;

        foreach ($all_orders as $order) {
            if (($order['master_id'] ?? '') !== $mid) continue;

            $status = $order['status'] ?? '';
            $total = floatval($order['total'] ?? 0);

            if ($status === CSC_STATUS_SEARCHING || $status === CSC_STATUS_PENDING) {
                $stats['pending']++;
            } elseif ($status === CSC_STATUS_PROCESSING) {
                $stats['active']++;
            } elseif ($status === CSC_STATUS_COMPLETED) {
                $stats['completed']++;
                // ИСПРАВЛЕНО: используем master_income вместо total
                $income = floatval($order['master_income'] ?? 0);
                if ($income <= 0) {
                    // Фолбэк: если master_income не указан
                    $income = max(0, $total - floatval($order['commission'] ?? 0));
                }
            } elseif ($status === CSC_STATUS_CANCELLED) {
                $stats['cancelled']++;
            }

            if (!empty($order['review']['rating'])) {
                $total_rating += intval($order['review']['rating']);
                $rating_count++;
            }
        }

        $stats['avg_rating'] = $rating_count > 0 ? round($total_rating / $rating_count, 1) : 0;

        return new WP_REST_Response([
            'success' => true,
            'data'    => ['stats' => $stats],
        ], 200);
    }
}
// ============================================================
// 9. ENDPOINT: ЗАГРУЗКА ФАЙЛОВ ОТЧЁТА О ВЫПОЛНЕНИИ
// ============================================================
if (!function_exists('csc_handle_upload_completion')) {
    function csc_handle_upload_completion($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        $order_number = sanitize_text_field($request->get_param('order_number'));
        $action = sanitize_text_field($request->get_param('action') ?? 'upload');

        // ИСПРАВЛЕНО (защита ЛК мастера): URL прокси-файлов не должен содержать
        // секрет доступа (раньше сюда попадал хэш постоянного dashboard_token).
        // В ссылки file-proxy подставляется «живой» токен сессии из текущего
        // авторизованного запроса (заголовок X-CSC-Token или параметр token) —
        // тот же, что использует страница кабинета. Одноразовые креденшелы сюда
        // не годятся: transient удаляется после первой сверки, и повторные
        // загрузки/просмотры файлов страницы ломались бы; stored-хэш не является
        // валидным предъявляемым значением. Если открытый токен запроса недоступен
        // (stored уже хэш) — файлы отдаются без токена в URL (см. фолбэк ниже),
        // доступ к ним возможен только через REST с заголовком авторизации.
        $file_proxy_token = sanitize_text_field($request->get_header('X-CSC-Token'));
        if ($file_proxy_token === '') {
            $file_proxy_token = sanitize_text_field($request->get_param('token'));
        }
        if ($file_proxy_token === '' || strlen($file_proxy_token) < 16) {
            $file_proxy_token = '';
        }

        if (empty($order_number)) {
            return new WP_Error('bad_request', 'Missing order_number', ['status' => 400]);
        }

        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        if (!isset($all_orders[$order_number])) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $order = &$all_orders[$order_number];
        if (($order['master_id'] ?? '') !== $mid && ($order['current_candidate'] ?? '') !== $mid) {
            return new WP_Error('forbidden', 'Not your order', ['status' => 403]);
        }

        // Действие "complete_confirm" — подтверждение выполнения
        if ($action === 'complete_confirm') {
            $completion_files = $order['completion_files'] ?? [];
            if (empty($completion_files)) {
                return new WP_Error('bad_request', 'Необходимо приложить фото или видео отчёт', ['status' => 400]);
            }

            $old_status = $order['status'] ?? CSC_STATUS_PROCESSING;
            $order['status'] = CSC_STATUS_COMPLETED;
            $order['completed_at'] = current_time('mysql');
            $order['routing_status'] = 'completed';

            if (function_exists('csc_update_order')) {
                csc_update_order($order_number, $order);
                if (function_exists('csc_cache_delete_orders')) csc_cache_delete_orders();
            } else {
                update_option('csc_all_orders', $all_orders, false);
            }

            if (function_exists('csc_log_status_change')) {
                csc_log_status_change(
                    $order_number,
                    $old_status,
                    CSC_STATUS_COMPLETED,
                    'master',
                    $mid,
                    'Выполнено с отчётом (' . count($completion_files) . ' файл(ов))'
                );
            }

            $customer_phone = function_exists('csc_normalize_phone')
                ? csc_normalize_phone($order['phone'] ?? '')
                : preg_replace('/[^0-9]/', '', $order['phone'] ?? '');

            if (function_exists('csc_log_notification')) {
                csc_log_notification($order_number, 'master', $mid, 'web',
                    "✅ Заказ выполнен с отчётом",
                    "Заказ #$order_number выполнен. Приложено файлов: " . count($completion_files),
                    ['completion_files' => count($completion_files)]
                );
                csc_log_notification($order_number, 'customer', $customer_phone, 'web',
                    "✅ Заказ выполнен",
                    "Ваш заказ #$order_number успешно выполнен мастером.",
                    ['trigger' => 'master_complete']
                );
            }

            $s = get_option('csc_main_settings', []);
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
            if (!empty($s['telegram_chat_id']) && function_exists('csc_send_tg_notification')) {
                $tg_msg = "✅ <b>Заказ #{$order_number} выполнен!</b>\n";
                $tg_msg .= "Мастер: " . ($masters[$mid]['name'] ?? $mid) . "\n";
                $tg_msg .= "Файлов отчёта: " . count($completion_files) . "\n";
                $tg_msg .= "Сумма: " . number_format($order['total'] ?? 0, 0, '.', ' ') . " ₽";
                csc_send_tg_notification($s['telegram_chat_id'], '✅ Заказ выполнен', $tg_msg, $order_number, 'admin', 'admin', null);
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Заказ отмечен как выполненный',
                'data'    => ['order' => $order],
            ], 200);
        }

        // Действие "upload" — загрузка файлов
        if ($action === 'upload') {
            if (!function_exists('wp_handle_upload')) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
            }

            $upload_dir = wp_upload_dir();
            $completion_dir = $upload_dir['basedir'] . '/csc-uploads/completion/' . $order_number;

            if (!file_exists($completion_dir)) {
                wp_mkdir_p($completion_dir);
                @file_put_contents($completion_dir . '/index.php', '<?php // Silence is golden.');
            }

            // ИСПРАВЛЕНО: Не удаляем .htaccess, а заменяем содержимое на корректное
            // Файлы отдаются через REST-прокси с авторизацией, прямой доступ запрещён
            $htaccess_file = $completion_dir . '/.htaccess';
            $htaccess_content = "# Защита файлов отчётов - доступ только через REST-прокси
            <FilesMatch \"\.(php|phtml|php3|php4|php5|phps)$\">
            Order Deny,Allow
            Deny from all
            </FilesMatch>
            Options -Indexes";
			
            @file_put_contents($htaccess_file, $htaccess_content);
            
            // Гарантируем правильные права доступа к папке
            @chmod($completion_dir, 0755);

            $files_array = $_FILES['completion_files'] ?? null;
            if (!$files_array || !is_array($files_array['name'])) {
                return new WP_Error('bad_request', 'Файлы не загружены', ['status' => 400]);
            }

            $existing_files = $order['completion_files'] ?? [];
            $existing_count = count($existing_files);

            $max_files = 10;
            $max_photo_size = 10 * 1024 * 1024;
            $max_video_size = 100 * 1024 * 1024;
            $allowed_image = ['jpg', 'jpeg', 'png', 'heic', 'webp'];
            $allowed_video = ['mp4', 'mov', 'avi', 'webm'];
            $allowed_types = array_merge($allowed_image, $allowed_video);
            $forbidden_types = ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'exe', 'sh', 'bat'];

            $uploaded = [];
            $file_count = count($files_array['name']);

            for ($i = 0; $i < $file_count; $i++) {
                if ($existing_count + count($uploaded) >= $max_files) break;
                if ($files_array['error'][$i] !== UPLOAD_ERR_OK) continue;

                $filename = $files_array['name'][$i];
                $tmp_name = $files_array['tmp_name'][$i];
                $file_size = $files_array['size'][$i];

                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                if (in_array($ext, $forbidden_types)) continue;
                if (!in_array($ext, $allowed_types)) continue;

                $is_image = in_array($ext, $allowed_image);
                $is_video = in_array($ext, $allowed_video);

                if ($is_image && $file_size > $max_photo_size) continue;
                if ($is_video && $file_size > $max_video_size) continue;

                $unique_name = 'comp_' . time() . '_' . $i . '_' . wp_generate_password(16, false) . '.' . $ext;
                $dest_path = $completion_dir . '/' . $unique_name;

                if (move_uploaded_file($tmp_name, $dest_path)) {
                    @chmod($dest_path, 0644);

                    // URL через прокси; токен включается только если доступен
                    // «живой» открытый токен сессии (иначе — доступ по заголовку)
                    $proxy_args = [
                        'order_number' => $order_number,
                        'filename'     => $unique_name,
                        'mid'          => $mid,
                    ];
                    if ($file_proxy_token !== '') {
                        $proxy_args['token'] = $file_proxy_token;
                    }
                    $file_url = add_query_arg($proxy_args, rest_url('csc/v1/master/file-proxy'));

                    $uploaded[] = [
                        'url'       => $file_url,
                        'name'      => $unique_name,
                        'type'      => $is_image ? 'image' : 'video',
                        'size'      => $file_size,
                        'uploaded'  => current_time('mysql'),
                    ];
                }
            }

            if (empty($uploaded)) {
                return new WP_Error('bad_request', 'Не удалось загрузить ни одного файла. Проверьте формат и размер.', ['status' => 400]);
            }

            $order['completion_files'] = array_merge($existing_files, $uploaded);

            if (function_exists('csc_update_order')) {
                csc_update_order($order_number, $order);
                if (function_exists('csc_cache_delete_orders')) csc_cache_delete_orders();
            } else {
                update_option('csc_all_orders', $all_orders, false);
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Загружено файлов: ' . count($uploaded),
                'data'    => [
                    'files' => $uploaded,
                    'total' => count($order['completion_files']),
                    'max'   => $max_files,
                ],
            ], 200);
        }

        // Действие "delete_file" — удаление файла отчёта
        if ($action === 'delete_file') {
            $file_index = intval($request->get_param('file_index'));
            $completion_files = $order['completion_files'] ?? [];

            if (!isset($completion_files[$file_index])) {
                return new WP_Error('not_found', 'Файл не найден', ['status' => 404]);
            }

            $file = $completion_files[$file_index];
            $upload_dir = wp_upload_dir();
            $file_path = $upload_dir['basedir'] . '/csc-uploads/completion/' . $order_number . '/' . $file['name'];

            if (file_exists($file_path)) {
                @unlink($file_path);
            }

            array_splice($completion_files, $file_index, 1);
            $order['completion_files'] = $completion_files;

            if (function_exists('csc_update_order')) {
                csc_update_order($order_number, $order);
                if (function_exists('csc_cache_delete_orders')) csc_cache_delete_orders();
            } else {
                update_option('csc_all_orders', $all_orders, false);
            }

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Файл удалён',
                'data'    => ['remaining' => count($completion_files)],
            ], 200);
        }

        return new WP_Error('bad_request', 'Unknown action', ['status' => 400]);
    }
}

// ============================================================
// 10. ENDPOINT: ПРОКСИ ДЛЯ БЕЗОПАСНОЙ ОТДАЧИ ФАЙЛОВ
// Работает при любых настройках сервера (.htaccess, Nginx, chmod)
// Отдаёт файл только авторизованному мастеру после полной проверки
// ============================================================
if (!function_exists('csc_handle_file_proxy')) {
    function csc_handle_file_proxy($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        $order_number = sanitize_text_field($request->get_param('order_number'));
        $filename = sanitize_file_name($request->get_param('filename'));

        if (empty($order_number) || empty($filename)) {
            return new WP_Error('bad_request', 'Missing parameters', ['status' => 400]);
        }

        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);
        if (!isset($all_orders[$order_number])) {
            return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        }

        $order = $all_orders[$order_number];
        if (($order['master_id'] ?? '') !== $mid && ($order['current_candidate'] ?? '') !== $mid) {
            return new WP_Error('forbidden', 'Not your order', ['status' => 403]);
        }

        // Проверка что файл действительно принадлежит этому заказу
        $file_exists_in_order = false;
        $completion_files = $order['completion_files'] ?? [];
        foreach ($completion_files as $file) {
            if (($file['name'] ?? '') === $filename) {
                $file_exists_in_order = true;
                break;
            }
        }
        if (!$file_exists_in_order) {
            return new WP_Error('forbidden', 'File not in this order', ['status' => 403]);
        }

        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/csc-uploads/completion/' . $order_number . '/' . $filename;

        if (!file_exists($file_path)) {
            return new WP_Error('not_found', 'File not found on disk', ['status' => 404]);
        }

        // Защита от path traversal (../../etc/passwd)
        $real_path = realpath($file_path);
        $real_base = realpath($upload_dir['basedir']);
        if ($real_path === false || $real_base === false || strpos($real_path, $real_base) !== 0) {
            return new WP_Error('forbidden', 'Invalid path', ['status' => 403]);
        }

        // Определяем MIME тип по содержимому файла
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_path);
        finfo_close($finfo);

        // Разрешаем только медиа-типы
        $allowed_mime = [
            'image/jpeg', 'image/png', 'image/webp', 'image/heic',
            'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/webm', 'video/avi'
        ];
        if (!in_array($mime_type, $allowed_mime) && strpos($mime_type, 'image/') !== 0 && strpos($mime_type, 'video/') !== 0) {
            return new WP_Error('forbidden', 'File type not allowed', ['status' => 403]);
        }

        // Очищаем буфер вывода чтобы не было мусора в ответе
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Отключаем лимиты времени и памяти для больших видео
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Отдаём файл с правильными заголовками
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . filesize($file_path));
        header('Content-Disposition: inline; filename="' . basename($file_path) . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');

        readfile($file_path);
        exit;
    }
}

// ============================================================
// 11. ENDPOINT: РЕДАКТИРОВАНИЕ ЗОНЫ ОБСЛУЖИВАНИЯ
// Сохраняет точку выезда (маркер) и радиус мастера
// ============================================================
if (!function_exists('csc_handle_update_location')) {
    function csc_handle_update_location($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;

        $mid = $auth['mid'];
        $masters = $auth['masters'];

        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));
        $radius = intval($request->get_param('radius'));
        $address = sanitize_text_field($request->get_param('address') ?? '');

        // Валидация координат (нулевые координаты недопустимы)
        if ($lat == 0 && $lng == 0) {
            return new WP_Error('bad_request', 'Укажите точку на карте', ['status' => 400]);
        }

        // Валидация радиуса: минимум 1 км, максимум 3 км
        if ($radius < 1) $radius = 1;
        if ($radius > 3) $radius = 3;

        $masters[$mid]['service_lat'] = $lat;
        $masters[$mid]['service_lng'] = $lng;
        $masters[$mid]['service_radius'] = $radius;

        if ($address !== '') {
            $masters[$mid]['service_address'] = $address;
        }

        update_option('csc_masters_list', $masters, false);
        if (function_exists('csc_cache_delete_masters')) {
            csc_cache_delete_masters();
        }

        // Логирование изменения зоны
        if (function_exists('csc_log_notification')) {
            csc_log_notification('', 'master', $mid, 'web',
                '📍 Зона обслуживания обновлена',
                "Радиус: {$radius} км, координаты: " . round($lat, 4) . ', ' . round($lng, 4),
                ['lat' => $lat, 'lng' => $lng, 'radius' => $radius]
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Зона обслуживания обновлена',
            'data'    => [
                'lat'    => $lat,
                'lng'    => $lng,
                'radius' => $radius,
            ],
        ], 200);
    }
}

// ============================================================
// ENDPOINT: Получение уведомлений мастера (версия 2.1)
// GET /wp-json/csc/v1/master/notifications
// ИСПРАВЛЕНО: используется единая авторизация через csc_verify_master_auth
// ============================================================
if (!function_exists('csc_handle_master_notifications')) {
    function csc_handle_master_notifications($request) {
        // Единая проверка авторизации (dashboard_token + hash_equals)
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $mid = $auth['mid'];

        // Читаем из хранилища внутрикабинетных уведомлений
        $option_key = 'csc_in_cabinet_master_' . $mid;
        $notifications = get_option($option_key, []);
        if (!is_array($notifications)) {
            $notifications = [];
        }

        $unread = 0;
        foreach ($notifications as $n) {
            if (empty($n['read'])) {
                $unread++;
            }
        }

        // Добавляем относительное время для отображения
        foreach ($notifications as &$n) {
            $n['time_ago'] = function_exists('csc_format_time_ago')
                ? csc_format_time_ago($n['created_at'] ?? '')
                : ($n['created_at'] ?? '');
        }
        unset($n);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'notifications' => $notifications,
                'unread_count'  => $unread,
                'total'         => count($notifications),
            ],
        ], 200);
    }
}

// ============================================================
// ENDPOINT: Пометить все уведомления как прочитанные
// ИСПРАВЛЕНО: используется единая авторизация через csc_verify_master_auth
// ============================================================
if (!function_exists('csc_handle_mark_master_notifications_read')) {
    function csc_handle_mark_master_notifications_read($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $mid = $auth['mid'];

        $option_key = 'csc_in_cabinet_master_' . $mid;
        $notifications = get_option($option_key, []);
        if (!is_array($notifications)) {
            $notifications = [];
        }

        foreach ($notifications as &$n) {
            $n['read'] = true;
        }
        unset($n);

        update_option($option_key, $notifications, false);

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Все уведомления помечены как прочитанные',
            'updated' => count($notifications),
        ], 200);
    }
}

// ============================================================
// ENDPOINT: Удалить одно уведомление
// ИСПРАВЛЕНО: используется единая авторизация через csc_verify_master_auth
// ============================================================
if (!function_exists('csc_handle_delete_master_notification')) {
    function csc_handle_delete_master_notification($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $mid = $auth['mid'];

        $nid = sanitize_text_field($request->get_param('notification_id') ?? '');
        if (empty($nid)) {
            return new WP_Error('bad_request', 'Missing notification_id', ['status' => 400]);
        }

        $option_key = 'csc_in_cabinet_master_' . $mid;
        $notifications = get_option($option_key, []);
        if (!is_array($notifications)) {
            $notifications = [];
        }

        $notifications = array_values(array_filter($notifications, function($n) use ($nid) {
            return ($n['id'] ?? '') !== $nid;
        }));

        update_option($option_key, $notifications, false);

        return new WP_REST_Response([
            'success'   => true,
            'message'   => 'Уведомление удалено',
            'remaining' => count($notifications),
        ], 200);
    }
}

// ============================================================
// Вспомогательная: форматирование времени "5 минут назад"
// ============================================================
if (!function_exists('csc_format_time_ago')) {
    function csc_format_time_ago($datetime) {
        if (empty($datetime)) return '';
        $ts = strtotime($datetime);
        if (!$ts) return '';
        $diff = time() - $ts;
        if ($diff < 0) return 'только что';
        if ($diff < 60) return 'только что';
        if ($diff < 3600) return floor($diff/60) . ' мин. назад';
        if ($diff < 86400) return floor($diff/3600) . ' ч. назад';
        if ($diff < 604800) return floor($diff/86400) . ' дн. назад';
        return date('d.m.Y', $ts);
    }
}

// ============================================================
// 14. ENDPOINT: БАЛАНС МАСТЕРА (ФИНАНСОВАЯ СВОДКА + ИСТОРИЯ)
// ============================================================
if (!function_exists('csc_handle_master_balance')) {
    function csc_handle_master_balance($request) {
        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;
        $mid = $auth['mid'];
        $masters = $auth['masters'];
        $master = $masters[$mid] ?? [];

        // --- Получаем все заказы мастера ---
        $all_orders = function_exists('csc_get_all_orders') ? csc_get_all_orders() : get_option('csc_all_orders', []);

        $total_earned       = 0.0;   // Сумма всех выполненных
        $month_earned       = 0.0;   // За текущий месяц
        $week_earned        = 0.0;   // За последние 7 дней
        $today_earned       = 0.0;   // За сегодня
        $pending_amount     = 0.0;   // В работе (ещё не заработано)
        $completed_count    = 0;
        $active_count       = 0;
        $cancelled_count    = 0;
        $total_commission   = 0.0;   // Комиссия платформы
        $master_income_total = 0.0;  // Чистый доход мастера

        $now_ts        = time();
        $today_str     = wp_date('Y-m-d');
        $month_str     = wp_date('Y-m');
        $week_ago_ts   = $now_ts - (7 * DAY_IN_SECONDS);

        // Комиссия мастера
        $comm_type = $master['comm_type'] ?? 'percent';
        $comm_val  = floatval($master['comm_val'] ?? 0);

        $transactions = [];

        foreach ($all_orders as $order) {
            if (($order['master_id'] ?? '') !== $mid) continue;

            $status     = $order['status'] ?? '';
            $order_total = floatval($order['total'] ?? 0);
            $order_date  = $order['date'] ?? '';
            $order_num   = $order['order_number'] ?? '';

            // Расчёт комиссии для этого заказа
            $order_commission = 0;
            if ($comm_type === 'percent') {
                $order_commission = $order_total * ($comm_val / 100);
            } else {
                $order_commission = $comm_val;
            }
            $order_master_income = max(0, $order_total - $order_commission);

            // Статусная аналитика
            if ($status === CSC_STATUS_COMPLETED || $status === 'completed' || $status === CSC_STATUS_COMPLETED) {
                $completed_count++;
                $total_earned       += $order_master_income;
                $master_income_total += $order_master_income;
                $total_commission   += $order_commission;

                // По периодам
                if ($order_date) {
                    if (strpos($order_date, $month_str) === 0) {
                        $month_earned += $order_master_income;
                    }
                    if ($order_date === $today_str) {
                        $today_earned += $order_master_income;
                    }
                    $order_ts = strtotime($order_date);
                    if ($order_ts && $order_ts >= $week_ago_ts) {
                        $week_earned += $order_master_income;
                    }
                }

                // Запись в историю транзакций
                $transactions[] = [
                    'type'        => 'income',
                    'label'       => 'Заказ #' . $order_num,
                    'amount'      => $order_master_income,
                    'commission'  => $order_commission,
                    'total'       => $order_total,
                    'date'        => $order['completed_at'] ?? $order_date,
                    'order_number'=> $order_num,
                    'icon'        => '✅',
                ];
            } elseif ($status === CSC_STATUS_PROCESSING) {
                $active_count++;
                $pending_amount += $order_master_income;
            } elseif ($status === CSC_STATUS_CANCELLED || $status === 'cancelled') {
                $cancelled_count++;
            }
        }

        // --- Штрафы из данных мастера ---
        $penalties_total  = intval($master['penalties_total'] ?? 0);
        $penalties_amount = floatval($master['penalties_amount'] ?? 0);

        // Добавляем штрафы как отрицательные транзакции
        if ($penalties_amount > 0) {
            $transactions[] = [
                'type'        => 'penalty',
                'label'       => 'Штрафы (' . $penalties_total . ' шт.)',
                'amount'      => -$penalties_amount,
                'commission'  => 0,
                'total'       => $penalties_amount,
                'date'        => '',
                'order_number'=> '',
                'icon'        => '⚠️',
            ];
        }

        // --- Выплаты (если хранятся у мастера) ---
        $payouts = $master['payouts'] ?? [];
        $total_payout = 0.0;
        if (is_array($payouts)) {
            foreach ($payouts as $p) {
                $p_amount = floatval($p['amount'] ?? 0);
                $p_status = $p['status'] ?? '';
                if ($p_status === 'completed' || $p_status === 'выплачено') { // 'выплачено' — legacy-статус выплаты (не статус заказа)
                    $total_payout += $p_amount;
                    $transactions[] = [
                        'type'        => 'payout',
                        'label'       => 'Выплата',
                        'amount'      => -$p_amount,
                        'commission'  => 0,
                        'total'       => $p_amount,
                        'date'        => $p['date'] ?? '',
                        'order_number'=> '',
                        'icon'        => '💸',
                    ];
                }
            }
        }

        // --- Текущий баланс (к выплате) ---
        $current_balance = $total_earned - $total_payout - $penalties_amount;
        if ($current_balance < 0) $current_balance = 0;

        // Сортировка транзакций: новые сверху
        usort($transactions, function($a, $b) {
            $ts_a = strtotime($a['date'] ?? '2000-01-01');
            $ts_b = strtotime($b['date'] ?? '2000-01-01');
            return $ts_b - $ts_a;
        });

        // Ограничиваем до 50 последних транзакций
        $transactions = array_slice($transactions, 0, 50);

        return new WP_REST_Response([
            'success' => true,
            'data'    => [
                'balance'         => round($current_balance, 2),
                'total_earned'    => round($total_earned, 2),
                'month_earned'    => round($month_earned, 2),
                'week_earned'     => round($week_earned, 2),
                'today_earned'    => round($today_earned, 2),
                'pending_amount'  => round($pending_amount, 2),
                'total_commission'=> round($total_commission, 2),
                'total_payout'    => round($total_payout, 2),
                'penalties_amount'=> round($penalties_amount, 2),
                'penalties_total' => $penalties_total,
                'completed_count' => $completed_count,
                'active_count'    => $active_count,
                'cancelled_count' => $cancelled_count,
                'comm_type'       => $comm_type,
                'comm_val'        => $comm_val,
                'transactions'    => $transactions,
            ],
        ], 200);
    }
}