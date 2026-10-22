<?php
/**
 * Файл: csc-master-registration.php
 * Описание: Публичная регистрация мастеров через форму
 * Версия: 2.1 (исправлены критические уязвимости загрузки, авторизации REST API и проверки токенов)
 *
 * Функционал:
 * - Справочник городов (поддомены + часовой пояс)
 * - Многоступенчатая форма регистрации
 * - Загрузка фото и документов
 * - Редактируемая оферта в админке
 * - Модерация заявок
 * - НОВОЕ 2.0: выбор зон обслуживания при регистрации
 * - НОВОЕ 2.0: сохранение work_days и work_hours_start/end из детального расписания
 */
if (!defined('ABSPATH')) exit;

// ============================================================
// 1. КОНФИГУРАЦИЯ ГОРОДОВ
// ============================================================
if (!function_exists('csc_get_cities')) {
    function csc_get_cities() {
        $default_cities = [
            'moskva' => [
                'name' => 'Москва',
                'subdomain' => 'moskva',
                'timezone' => 'Europe/Moscow',
                'active' => true,
            ],
            'spb' => [
                'name' => 'Санкт-Петербург',
                'subdomain' => 'spb',
                'timezone' => 'Europe/Moscow',
                'active' => true,
            ],
            'kazan' => [
                'name' => 'Казань',
                'subdomain' => 'kazan',
                'timezone' => 'Europe/Moscow',
                'active' => true,
            ],
            'novosibirsk' => [
                'name' => 'Новосибирск',
                'subdomain' => 'novosibirsk',
                'timezone' => 'Asia/Novosibirsk',
                'active' => true,
            ],
            'ekb' => [
                'name' => 'Екатеринбург',
                'subdomain' => 'ekb',
                'timezone' => 'Asia/Yekaterinburg',
                'active' => true,
            ],
        ];
        $cities = get_option('csc_cities', []);
        if (empty($cities)) {
            update_option('csc_cities', $default_cities, false);
            return $default_cities;
        }
        return $cities;
    }
}

// ============================================================
// 2. ОПРЕДЕЛЕНИЕ ТЕКУЩЕГО ГОРОДА
// ============================================================
if (!function_exists('csc_get_current_city')) {
    function csc_get_current_city() {
        $cities = csc_get_cities();
        // Способ 1: Параметр в URL (?city=moskva)
        if (isset($_GET['city']) && !empty($_GET['city'])) {
            $city_key = sanitize_title($_GET['city']);
            if (isset($cities[$city_key]) && $cities[$city_key]['active']) {
                return $city_key;
            }
        }
        // Способ 2: Поддомен (moskva.site.ru)
        $host = $_SERVER['HTTP_HOST'] ?? '';
        // ИСПРАВЛЕНО: Отсекаем порт (например, site.ru:8080), иначе explode сломается
        $host = explode(':', $host)[0];
        $parts = explode('.', $host);
        if (count($parts) > 2) { // есть поддомен
            $subdomain = strtolower($parts[0]);
            foreach ($cities as $key => $city) {
                if ($city['subdomain'] === $subdomain && $city['active']) {
                    return $key;
                }
            }
        }
        // Способ 3: Сохранённый в сессии/куках
        if (isset($_COOKIE['csc_city']) && isset($cities[$_COOKIE['csc_city']])) {
            return $_COOKIE['csc_city'];
        }
        // По умолчанию: первый активный город
        foreach ($cities as $key => $city) {
            if ($city['active']) return $key;
        }
        return null;
    }
}

// ============================================================
// 3. ПОЛУЧЕНИЕ ТЕКСТА ОФЕРТЫ
// ============================================================
if (!function_exists('csc_get_agreement_text')) {
    function csc_get_agreement_text() {
        $default_text = '
<h3>Соглашение об оказании услуг</h3>
<p>Настоящее соглашение регулирует условия взаимодействия между Сервисом и Мастером.</p>
<h4>1. Общие положения</h4>
<p>1.1. Мастер обязуется выполнять заказы клиентов качественно и в срок.</p>
<p>1.2. Сервис предоставляет платформу для поиска клиентов и управления заказами.</p>
<h4>2. Ответственность мастера</h4>
<p>2.1. Мастер несёт ответственность за невыполнение принятых заявок.</p>
<p>2.2. За каждый отказ от заявки во время активности предусмотрен штраф.</p>
<p>2.3. За невыполнение принятой заявки в назначенное время предусмотрен штраф.</p>
<h4>3. Система штрафов</h4>
<p>3.1. Размер штрафов устанавливается администрацией Сервиса.</p>
<p>3.2. При превышении лимита штрафов мастер может быть заблокирован.</p>
<p>3.3. Блокировка снимается после урегулирования задолженности.</p>
<h4>4. Оплата</h4>
<p>4.1. Оплата услуг производится клиентом напрямую мастеру.</p>
<p>4.2. Сервис не несёт ответственности за расчёты между мастером и клиентом.</p>
<h4>5. Заключительные положения</h4>
<p>5.1. Сервис вправе изменить условия соглашения с уведомлением мастеров.</p>
<p>5.2. Продолжение использования Сервиса означает согласие с новыми условиями.</p>
';
        return get_option('csc_master_agreement_text', $default_text);
    }
}

// ============================================================
// 4. НАСТРОЙКИ РЕГИСТРАЦИИ И ШТРАФОВ
// ============================================================
if (!function_exists('csc_get_registration_settings')) {
    function csc_get_registration_settings() {
        $defaults = [
            // Модерация
            'auto_approve' => false, // false = требуется модерация
            // Штрафы
            'penalty_rejection_amount' => 500,
            'penalty_rejection_type' => 'fixed', // fixed | percent
            'penalty_rejection_percent' => 10,
            'penalty_no_show_amount' => 1000,
            'penalty_no_show_type' => 'fixed',
            'penalty_no_show_percent' => 20,
            'penalty_late_amount' => 300,
            'penalty_late_type' => 'fixed',
            'penalty_late_percent' => 5,
            // Лимиты
            'max_rejections_before_block' => 5,
            'forgiveness_ratio_rejection' => 4, // 4 выполненные подряд = 1 отказ прощён
            'forgiveness_ratio_no_show' => 2,   // 2 выполненные подряд = 1 невыход прощён
			'zone_max_masters' => 0,   // 0 = лимит выключен; иначе максимум мастеров в ячейке
            // Блокировка
            'block_penalty_amount' => 5000, // сумма штрафов для блокировки
            // Уведомления
            'notify_admin_on_rejection' => true,
            'notify_master_on_rejection' => true,
            'notify_admin_on_block' => true,
            'notify_master_on_block' => true,
        ];
        $settings = get_option('csc_master_registration_settings', []);
        return wp_parse_args($settings, $defaults);
    }
}
// ============================================================
// 5. ШОРТКОД [csc_become_master]
// ============================================================
add_shortcode('csc_become_master', 'csc_render_become_master_form');

if (!function_exists('csc_render_become_master_form')) {
    function csc_render_become_master_form($atts) {
        $current_city = csc_get_current_city();
        $cities = csc_get_cities();
        $settings = csc_get_registration_settings();
        $agreement_text = csc_get_agreement_text();

        if (isset($_GET['mid']) && isset($_GET['token'])) {
            $mid = sanitize_text_field($_GET['mid']);
            $token = sanitize_text_field($_GET['token']);
            $masters = function_exists('csc_get_masters') ? csc_get_masters() : [];
            
            if (isset($masters[$mid]) && isset($masters[$mid]['dashboard_token']) && $masters[$mid]['dashboard_token'] === $token) {
                return '<div style="padding:40px;text-align:center;background:#dcfce7;border:2px solid #86efac;border-radius:12px;max-width:700px;margin:0 auto;">Вы уже зарегистрированы. <a href="' . esc_url(home_url('/?csc_master_dashboard=1&mid=' . urlencode($mid) . '&token=' . urlencode($token))) . '">Перейти в кабинет</a></div>';
            }
        }

        // КРИТИЧНО ИСПРАВЛЕНО: Leaflet загружается ПЕРЕД нашим скриптом (в header, не в footer)
        // Это гарантирует что L будет доступен когда наш JS вызовет initRegistrationMap()
        wp_enqueue_style('csc-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('csc-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', false);
		
        wp_enqueue_style('csc-master-registration');
        wp_enqueue_script('csc-master-registration');

        $city_centers = [
            'moskva'      => [55.7558, 37.6173],
            'spb'         => [59.9343, 30.3351],
            'kazan'       => [55.8304, 49.0661],
            'novosibirsk' => [55.0084, 82.9357],
            'ekb'         => [56.8389, 60.6057],
        ];
        $map_center = $city_centers[$current_city] ?? [55.7558, 37.6173];

        wp_localize_script('csc-master-registration', 'CSC_REGISTRATION_CONFIG', [
            'restUrl' => rest_url('csc/v1/'),
            'cities' => $cities,
            'currentCity' => $current_city,
            'autoApprove' => $settings['auto_approve'],
            'mapCenter' => $map_center,
            'radiusMin' => 3,
            'radiusMax' => 20,
            'radiusDefault' => 10,
            'nonce' => wp_create_nonce('wp_rest'),
        ]);

        ob_start();
        
        // КРИТИЧНО ИСПРАВЛЕНО: Стили выводятся ПРЯМО В HTML через echo
        // wp_head уже отработал, поэтому add_action бесполезен
        echo '<style id="csc-registration-inline-styles">
            .csc-registration-page {
                min-height: 100vh !important;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
                padding: 40px 20px !important;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif !important;
                display: flex !important;
                align-items: flex-start !important;
                justify-content: center !important;
                box-sizing: border-box !important;
            }
            
            .csc-registration-container {
                width: 100% !important;
                max-width: 760px !important;
                margin: 0 auto !important;
                background: #ffffff !important;
                border-radius: 16px !important;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3) !important;
                padding: 48px !important;
                box-sizing: border-box !important;
            }
            
            /* КРИТИЧНО: СКРЫТИЕ НЕАКТИВНЫХ ШАГОВ */
            .csc-form-step {
                display: none !important;
                visibility: hidden !important;
                height: 0 !important;
                overflow: hidden !important;
                opacity: 0 !important;
            }
            
            .csc-form-step.active {
                display: block !important;
                visibility: visible !important;
                height: auto !important;
                overflow: visible !important;
                opacity: 1 !important;
                animation: cscFadeIn 0.4s ease !important;
            }
            
            @keyframes cscFadeIn {
                from { opacity: 0; transform: translateY(20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            /* КРИТИЧНО: СКРЫТИЕ БЛОКОВ УСПЕХА/ОШИБКИ */
            #csc-registration-error,
            #csc-registration-success {
                display: none !important;
            }
            
            /* КАРТА (ИСПРАВЛЕНО: добавлены position и overflow) */
            #csc-reg-map {
                width: 100% !important;
                height: 340px !important;
                min-height: 340px !important;
                border-radius: 12px !important;
                border: 2px solid #e2e8f0 !important;
                background: #f1f5f9 !important;
                margin-top: 12px !important;
                z-index: 1 !important;
                position: relative !important;
                overflow: hidden !important;
            }
            
            #csc-reg-map .leaflet-container {
                width: 100% !important;
                height: 100% !important;
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                bottom: 0 !important;
            }
            
            /* ЗАГОЛОВОК */
            .csc-registration-header {
                text-align: center !important;
                margin-bottom: 40px !important;
            }
            
            .csc-registration-title {
                font-size: 32px !important;
                font-weight: 800 !important;
                color: #1e293b !important;
                margin: 0 0 8px !important;
            }
            
            .csc-registration-subtitle {
                color: #64748b !important;
                font-size: 16px !important;
                margin: 0 !important;
            }
            
            /* ПРОГРЕСС-БАР ПОЛНОТЫ */
            .csc-profile-completeness {
                background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%) !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 12px !important;
                padding: 24px !important;
                margin-bottom: 32px !important;
            }
            
            .csc-completeness-header {
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
                margin-bottom: 14px !important;
            }
            
            .csc-completeness-label {
                font-size: 13px !important;
                font-weight: 700 !important;
                color: #1e293b !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
            }
            
            .csc-completeness-value {
                font-size: 22px !important;
                font-weight: 800 !important;
                color: #6366f1 !important;
            }
            
            .csc-completeness-bar {
                width: 100% !important;
                height: 14px !important;
                background: #e2e8f0 !important;
                border-radius: 7px !important;
                overflow: hidden !important;
            }
            
            #csc-completeness-fill {
                height: 100% !important;
                width: 0% !important;
                background: linear-gradient(90deg, #6366f1 0%, #4f46e5 100%) !important;
                border-radius: 7px !important;
                transition: width 0.4s ease !important;
            }
            
            /* ПРОГРЕСС ШАГОВ */
            .csc-registration-progress {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                margin-bottom: 40px !important;
            }
            
            .csc-progress-step {
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                gap: 10px !important;
            }
            
            .csc-progress-step-number {
                width: 44px !important;
                height: 44px !important;
                border-radius: 50% !important;
                background: #e2e8f0 !important;
                color: #64748b !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-weight: 700 !important;
                font-size: 16px !important;
                transition: all 0.3s !important;
            }
            
            .csc-progress-step.active .csc-progress-step-number {
                background: #6366f1 !important;
                color: #ffffff !important;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4) !important;
                transform: scale(1.1) !important;
            }
            
            .csc-progress-step.completed .csc-progress-step-number {
                background: #10b981 !important;
                color: #ffffff !important;
                font-size: 0 !important;
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4) !important;
            }
            
            .csc-progress-step.completed .csc-progress-step-number::after {
                content: "✓" !important;
                font-size: 20px !important;
                font-weight: 700 !important;
            }
            
            .csc-progress-step-label {
                font-size: 12px !important;
                color: #64748b !important;
                font-weight: 600 !important;
                text-transform: uppercase !important;
            }
            
            .csc-progress-step.active .csc-progress-step-label {
                color: #6366f1 !important;
            }
            
            .csc-progress-line {
                flex: 1 !important;
                max-width: 80px !important;
                height: 2px !important;
                background: #e2e8f0 !important;
                margin: 0 12px !important;
            }
            
            /* СОГЛАШЕНИЕ */
            .csc-agreement-box {
                max-height: 320px !important;
                overflow-y: auto !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 12px !important;
                padding: 24px !important;
                margin-bottom: 24px !important;
                background: #f8fafc !important;
            }
            
            .csc-agreement-content h3,
            .csc-agreement-content h4 {
                color: #1e293b !important;
                margin-top: 20px !important;
                margin-bottom: 10px !important;
            }
            
            .csc-agreement-content p {
                color: #64748b !important;
                line-height: 1.7 !important;
                margin-bottom: 10px !important;
            }
            
            /* ПОЛЯ ВВОДА */
            .csc-form-group {
                margin-bottom: 24px !important;
            }
            
            .csc-form-group label {
                display: block !important;
                font-weight: 600 !important;
                margin-bottom: 8px !important;
                color: #1e293b !important;
                font-size: 14px !important;
            }
            
            .csc-form-group input[type="text"],
            .csc-form-group input[type="tel"],
            .csc-form-group input[type="email"],
            .csc-form-group input[type="number"],
            .csc-form-group input[type="time"],
            .csc-form-group select,
            .csc-form-group textarea {
                width: 100% !important;
                padding: 14px 16px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 8px !important;
                font-size: 15px !important;
                transition: all 0.3s !important;
                background: #ffffff !important;
                color: #1e293b !important;
                box-sizing: border-box !important;
            }
            
            .csc-form-group input:focus,
            .csc-form-group select:focus,
            .csc-form-group textarea:focus {
                outline: none !important;
                border-color: #6366f1 !important;
                box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1) !important;
            }
            
            .csc-form-group input::placeholder,
            .csc-form-group textarea::placeholder {
                color: #94a3b8 !important;
            }
            
            .csc-form-group select[multiple] {
                min-height: 140px !important;
                padding: 12px !important;
            }
            
            .csc-form-group select[multiple] option:checked {
                background: #6366f1 !important;
                color: #ffffff !important;
            }
            
            .csc-form-group small {
                display: block !important;
                margin-top: 6px !important;
                color: #64748b !important;
                font-size: 12px !important;
            }
            
            .csc-form-grid {
                display: grid !important;
                grid-template-columns: 1fr 1fr !important;
                gap: 20px !important;
            }
            
            /* ЧЕКБОКСЫ */
            .csc-checkbox-label {
                display: flex !important;
                align-items: flex-start !important;
                gap: 12px !important;
                cursor: pointer !important;
                padding: 16px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 8px !important;
                transition: all 0.3s !important;
                background: #ffffff !important;
            }
            
            .csc-checkbox-label:hover {
                border-color: #818cf8 !important;
            }
            
            .csc-checkbox-label input[type="checkbox"] {
                width: 22px !important;
                height: 22px !important;
                margin-top: 2px !important;
                accent-color: #6366f1 !important;
                cursor: pointer !important;
                flex-shrink: 0 !important;
            }
            
            .csc-checkbox-text {
                color: #1e293b !important;
                font-weight: 500 !important;
                line-height: 1.6 !important;
            }
            
            /* РАДИО */
            .csc-radio-label {
                display: flex !important;
                align-items: center !important;
                gap: 12px !important;
                cursor: pointer !important;
                padding: 14px 16px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 8px !important;
                transition: all 0.3s !important;
                background: #ffffff !important;
                margin-bottom: 8px !important;
            }
            
            .csc-radio-label:hover {
                border-color: #818cf8 !important;
            }
            
            .csc-radio-label input[type="radio"] {
                width: 20px !important;
                height: 20px !important;
                accent-color: #6366f1 !important;
                cursor: pointer !important;
            }
            
            /* ЗАГРУЗКА ФАЙЛОВ */
            .csc-file-upload {
                border: 3px dashed #e2e8f0 !important;
                border-radius: 12px !important;
                padding: 40px !important;
                text-align: center !important;
                cursor: pointer !important;
                transition: all 0.3s !important;
                position: relative !important;
                background: #f8fafc !important;
            }
            
            .csc-file-upload:hover {
                border-color: #6366f1 !important;
                background: rgba(99, 102, 241, 0.04) !important;
            }
            
            .csc-file-upload input[type="file"] {
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                height: 100% !important;
                opacity: 0 !important;
                cursor: pointer !important;
            }
            
            .csc-file-upload-icon {
                font-size: 48px !important;
                margin-bottom: 12px !important;
                display: block !important;
            }
            
            .csc-file-upload-text {
                color: #64748b !important;
                font-weight: 600 !important;
                font-size: 15px !important;
            }
            
            .csc-file-preview {
                margin-top: 16px !important;
            }
            
            /* РАСПИСАНИЕ */
            .csc-schedule-info {
                background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%) !important;
                border: 2px solid #bfdbfe !important;
                border-radius: 12px !important;
                padding: 20px !important;
                margin-bottom: 24px !important;
            }
            
            .csc-schedule-info p {
                margin: 0 0 8px !important;
                color: #1e40af !important;
                font-weight: 500 !important;
            }
            
            .csc-schedule-grid {
                display: flex !important;
                flex-direction: column !important;
                gap: 12px !important;
            }
            
            .csc-schedule-day {
                display: flex !important;
                align-items: center !important;
                gap: 20px !important;
                padding: 18px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 8px !important;
                flex-wrap: wrap !important;
                background: #ffffff !important;
            }
            
            .csc-schedule-times {
                display: none;
                align-items: center;
                gap: 12px;
                margin-left: auto;
            }
            
            .csc-time-input {
                padding: 10px 14px !important;
                border: 2px solid #e2e8f0 !important;
                border-radius: 8px !important;
                font-size: 14px !important;
                font-weight: 600 !important;
            }
            
            /* КНОПКИ */
            .csc-form-actions {
                display: flex !important;
                justify-content: space-between !important;
                gap: 16px !important;
                margin-top: 40px !important;
                padding-top: 32px !important;
                border-top: 2px solid #e2e8f0 !important;
            }
            
            .csc-btn {
                padding: 16px 32px !important;
                border: none !important;
                border-radius: 8px !important;
                font-size: 15px !important;
                font-weight: 700 !important;
                cursor: pointer !important;
                transition: all 0.3s !important;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 10px !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
                text-decoration: none !important;
            }
            
            .csc-btn-primary {
                background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%) !important;
                color: #ffffff !important;
                box-shadow: 0 4px 14px rgba(99, 102, 241, 0.4) !important;
            }
            
            .csc-btn-primary:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 20px rgba(99, 102, 241, 0.5) !important;
            }
            
            .csc-btn-secondary {
                background: #e2e8f0 !important;
                color: #1e293b !important;
            }
            
            .csc-btn-secondary:hover {
                background: #cbd5e1 !important;
                transform: translateY(-2px) !important;
            }
            
            .csc-btn-success {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%) !important;
                color: #ffffff !important;
                box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4) !important;
            }
            
            .csc-btn-success:hover {
                transform: translateY(-2px) !important;
                box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5) !important;
            }
            
            .csc-btn:disabled {
                opacity: 0.5 !important;
                cursor: not-allowed !important;
                transform: none !important;
            }
            
            /* ПОЛЗУНОК */
            #csc-reg-radius {
                -webkit-appearance: none !important;
                appearance: none !important;
                width: 100% !important;
                height: 10px !important;
                border-radius: 5px !important;
                background: linear-gradient(90deg, #6366f1 0%, #c7d2fe 100%) !important;
                outline: none !important;
                cursor: pointer !important;
                margin: 12px 0 !important;
            }
            
            #csc-reg-radius::-webkit-slider-thumb {
                -webkit-appearance: none !important;
                appearance: none !important;
                width: 28px !important;
                height: 28px !important;
                border-radius: 50% !important;
                background: #6366f1 !important;
                border: 4px solid #ffffff !important;
                box-shadow: 0 3px 8px rgba(99, 102, 241, 0.5) !important;
                cursor: grab !important;
            }
            
            #csc-reg-radius::-moz-range-thumb {
                width: 28px !important;
                height: 28px !important;
                border-radius: 50% !important;
                background: #6366f1 !important;
                border: 4px solid #ffffff !important;
                box-shadow: 0 3px 8px rgba(99, 102, 241, 0.5) !important;
                cursor: grab !important;
            }
            
            #csc-reg-radius-value {
                font-weight: 800 !important;
                color: #6366f1 !important;
                font-size: 18px !important;
            }
            
            #csc-geocode-address-btn {
                display: inline-flex !important;
                align-items: center !important;
                gap: 10px !important;
                padding: 12px 20px !important;
                border: 2px solid #6366f1 !important;
                border-radius: 8px !important;
                background: #ffffff !important;
                color: #6366f1 !important;
                font-size: 14px !important;
                font-weight: 700 !important;
                cursor: pointer !important;
                transition: all 0.3s !important;
                margin-top: 12px !important;
            }
            
            #csc-geocode-address-btn:hover:not(:disabled) {
                background: #6366f1 !important;
                color: #ffffff !important;
            }
            
            /* СПИННЕР */
            .csc-spinner {
                display: inline-block !important;
                width: 18px !important;
                height: 18px !important;
                border: 3px solid rgba(255,255,255,0.3) !important;
                border-radius: 50% !important;
                border-top-color: #ffffff !important;
                animation: cscSpin 0.8s linear infinite !important;
            }
            
            @keyframes cscSpin {
                to { transform: rotate(360deg); }
            }
            
            /* АДАПТИВНОСТЬ */
            @media (max-width: 768px) {
                }
                #csc-reg-map {
                    height: 280px !important;
            }
        </style>';
        ?>
        <div class="csc-registration-page">
            <div class="csc-registration-container">
                <div class="csc-registration-header">
                    <h1 class="csc-registration-title">Стать мастером</h1>
                    <p class="csc-registration-subtitle">Присоединяйтесь к нашей команде профессионалов</p>
                </div>
				
                <div class="csc-profile-completeness" id="csc-profile-completeness">
                    <div class="csc-completeness-header">
                        <span class="csc-completeness-label">Заполненность карточки</span>
                        <span class="csc-completeness-value" id="csc-completeness-text">0%</span>
                    </div>
                    <div class="csc-completeness-bar">
                        <div class="csc-completeness-fill" id="csc-completeness-fill"></div>
                    </div>
                </div>

                <div id="csc-registration-error" style="display:none; text-align:center; padding:32px; background:#fef2f2; border:2px solid #fecaca; border-radius:12px; margin-bottom:32px;">
                    <div style="font-size:64px; margin-bottom:16px;">❌</div>
                    <h2 style="color:#ef4444; margin:0 0 12px; font-size:24px; font-weight:800;">Ошибка</h2>
                    <p id="csc-error-message" style="color:#64748b; margin:0; font-size:15px; line-height:1.6;"></p>
                </div>

                <div id="csc-registration-success" style="display:none; text-align:center; padding:32px; background:#f0fdf4; border:2px solid #bbf7d0; border-radius:12px; margin-bottom:32px;">
                    <div style="font-size:64px; margin-bottom:16px;">✅</div>
                    <h2 style="color:#10b981; margin:0 0 12px; font-size:24px; font-weight:800;">Заявка отправлена!</h2>
                    <p style="color:#64748b; margin:8px 0; font-size:15px; line-height:1.6;">Ваша заявка на регистрацию принята.</p>
                    <p style="color:#64748b; margin:8px 0; font-size:15px; line-height:1.6;">Администрация проверит ваши данные и свяжется с вами в ближайшее время.</p>
                </div>

                <div class="csc-registration-progress">
                    <div class="csc-progress-step active" data-step="1">
                        <div class="csc-progress-step-number">1</div>
                        <div class="csc-progress-step-label">Соглашение</div>
                    </div>
                    <div class="csc-progress-line"></div>
                    <div class="csc-progress-step" data-step="2">
                        <div class="csc-progress-step-number">2</div>
                        <div class="csc-progress-step-label">Анкета</div>
                    </div>
                    <div class="csc-progress-line"></div>
                    <div class="csc-progress-step" data-step="3">
                        <div class="csc-progress-step-number">3</div>
                        <div class="csc-progress-step-label">Документы</div>
                    </div>
                    <div class="csc-progress-line"></div>
                    <div class="csc-progress-step" data-step="4">
                        <div class="csc-progress-step-number">4</div>
                        <div class="csc-progress-step-label">Расписание</div>
                    </div>
                </div>

                <form id="csc-registration-form" enctype="multipart/form-data">

                    <div class="csc-form-step active" data-step="1">
                        <div class="csc-agreement-box">
                            <div class="csc-agreement-content"><?php echo wp_kses_post($agreement_text); ?></div>
                        </div>
                        <div class="csc-form-group">
                            <label class="csc-checkbox-label">
                                <input type="checkbox" name="accept_agreement" id="csc-accept-agreement" value="1" required>
                                <span class="csc-checkbox-text">Я принимаю условия соглашения и обязуюсь их выполнять</span>
                            </label>
                        </div>
                        <div class="csc-form-group">
                            <label for="csc-reg-city">Ваш город</label>
                            <select name="city" id="csc-reg-city" required>
                                <option value="">Выберите город</option>
                                <?php foreach ($cities as $key => $city): if (!$city['active']) continue; ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($current_city, $key); ?>>
                                        <?php echo esc_html($city['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="csc-form-actions">
                            <button type="button" class="csc-btn csc-btn-primary" onclick="cscRegistrationNextStep(1)">Продолжить →</button>
                        </div>
                    </div>

                    <div class="csc-form-step" data-step="2">
                        <div class="csc-form-grid">
                            <div class="csc-form-group">
                                <label for="csc-reg-name">ФИО *</label>
                                <input type="text" name="name" id="csc-reg-name" required placeholder="Иванов Иван Иванович">
                            </div>
                            <div class="csc-form-group">
                                <label for="csc-reg-phone">Телефон *</label>
                                <input type="tel" name="phone" id="csc-reg-phone" required placeholder="+7 (999) 123-45-67">
                            </div>
                            <div class="csc-form-group">
                                <label for="csc-reg-email">Email *</label>
                                <input type="email" name="email" id="csc-reg-email" required placeholder="example@mail.ru">
                            </div>
                            <div class="csc-form-group">
                                <label for="csc-reg-experience">Опыт работы (лет)</label>
                                <input type="number" name="experience_years" id="csc-reg-experience" min="0" max="50" value="0">
                            </div>
                        </div>

                        <div class="csc-form-group">
                            <label for="csc-reg-specializations">Специализация *</label>
                            <select name="specializations[]" id="csc-reg-specializations" multiple required>
                                <option value="электрик">Электрик</option>
                                <option value="сантехник">Сантехник</option>
                                <option value="мастер_на_час">Мастер на час</option>
                                <option value="плиточник">Плиточник</option>
                                <option value="маляр">Маляр</option>
                                <option value="сборщик_мебели">Сборщик мебели</option>
                                <option value="другое">Другое</option>
                            </select>
                            <small>Удерживайте Ctrl для выбора нескольких</small>
                        </div>

                        <div class="csc-form-group">
                            <label for="csc-reg-service-address">Адрес проживания / точка выезда *</label>
                            <input type="text" name="service_address" id="csc-reg-service-address" required placeholder="Например: Москва, ул. Тверская, д. 1">
                            <small>Укажите адрес, откуда вы будете выезжать на заказы.</small>
                            <button type="button" id="csc-geocode-address-btn">🔍 Найти на карте</button>
                        </div>

                        <div class="csc-form-group">
                            <label>Зона обслуживания (радиус выезда) *</label>
                            <div id="csc-reg-map" style="width:100%;height:340px;position:relative;overflow:hidden;border-radius:12px;border:2px solid #e2e8f0;background:#f1f5f9;margin-top:12px;"></div>
							<div id="csc-zone-warning" style="display:none;margin-top:10px;padding:12px 16px;border:2px solid #fde68a;border-radius:8px;font-size:14px;font-weight:600;"></div>
                            <div style="margin-top:16px;">
                                <label for="csc-reg-radius" style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                                    Радиус: <span id="csc-reg-radius-value">10</span> км
                                </label>
                                <input type="range" name="service_radius" id="csc-reg-radius" min="3" max="20" value="10" step="1">
                                <small>Минимум 3 км, максимум 20 км.</small>
                            </div>
                            <input type="hidden" name="service_lat" id="csc-reg-lat" value="">
                            <input type="hidden" name="service_lng" id="csc-reg-lng" value="">
                        </div>

                        <div class="csc-form-group">
                            <label for="csc-reg-about">О себе</label>
                            <textarea name="about" id="csc-reg-about" rows="4" placeholder="Расскажите о своём опыте..."></textarea>
                        </div>

                        <div class="csc-form-group">
                            <label for="csc-reg-photo">Ваше фото</label>
                            <div class="csc-file-upload">
                                <input type="file" name="photo" id="csc-reg-photo" accept="image/*">
                                <div class="csc-file-upload-icon">📷</div>
                                <div class="csc-file-upload-text">Нажмите для загрузки фото</div>
                                <div class="csc-file-preview" id="csc-photo-preview"></div>
                            </div>
                        </div>

                        <div class="csc-form-actions">
                            <button type="button" class="csc-btn csc-btn-secondary" onclick="cscRegistrationPrevStep(2)">← Назад</button>
                            <button type="button" class="csc-btn csc-btn-primary" onclick="cscRegistrationNextStep(2)">Продолжить →</button>
                        </div>
                    </div>

                    <div class="csc-form-step" data-step="3">
                        <div class="csc-schedule-info">
                            <p>Для подтверждения личности необходимо загрузить документ.</p>
                            <p><strong>Важно:</strong> Ваши документы видны только администрации.</p>
                        </div>
                        <div class="csc-form-group">
                            <label>Тип документа *</label>
                            <div>
                                <label class="csc-radio-label">
                                    <input type="radio" name="document_type" value="passport" checked>
                                    <span>Паспорт</span>
                                </label>
                                <label class="csc-radio-label">
                                    <input type="radio" name="document_type" value="license">
                                    <span>Водительские права</span>
                                </label>
                                <label class="csc-radio-label">
                                    <input type="radio" name="document_type" value="other">
                                    <span>Другой документ</span>
                                </label>
                            </div>
                        </div>
                        <div class="csc-form-group">
                            <label for="csc-reg-document">Фото документа *</label>
                            <div class="csc-file-upload">
                                <input type="file" name="document_photo" id="csc-reg-document" accept="image/*" required>
                                <div class="csc-file-upload-icon">📄</div>
                                <div class="csc-file-upload-text">Нажмите для загрузки документа</div>
                                <div class="csc-file-preview" id="csc-document-preview"></div>
                            </div>
                        </div>
                        <div class="csc-form-actions">
                            <button type="button" class="csc-btn csc-btn-secondary" onclick="cscRegistrationPrevStep(3)">← Назад</button>
                            <button type="button" class="csc-btn csc-btn-primary" onclick="cscRegistrationNextStep(3)">Продолжить →</button>
                        </div>
                    </div>

                    <div class="csc-form-step" data-step="4">
                        <div class="csc-schedule-info">
                            <p>Выберите дни и время, когда вы готовы принимать заказы.</p>
                            <p><strong>Важно:</strong> минимальная продолжительность рабочего дня — 4 часа.</p>
                        </div>
                        <div class="csc-schedule-grid">
                            <?php
                            $days = ['monday'=>'Понедельник','tuesday'=>'Вторник','wednesday'=>'Среда','thursday'=>'Четверг','friday'=>'Пятница','saturday'=>'Суббота','sunday'=>'Воскресенье'];
                            foreach ($days as $day_key => $day_name):
                            ?>
                            <div class="csc-schedule-day" data-day="<?php echo $day_key; ?>">
                                <label class="csc-checkbox-label">
                                    <input type="checkbox" name="schedule[<?php echo $day_key; ?>][enabled]" value="1" class="csc-schedule-day-toggle">
                                    <span class="csc-checkbox-text"><?php echo $day_name; ?></span>
                                </label>
                                <div class="csc-schedule-times">
                                    <input type="time" name="schedule[<?php echo $day_key; ?>][from]" value="09:00" class="csc-time-input">
                                    <span>до</span>
                                    <input type="time" name="schedule[<?php echo $day_key; ?>][to]" value="18:00" class="csc-time-input">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="csc-form-group" style="margin-top:24px;">
                            <label class="csc-checkbox-label">
                                <input type="checkbox" name="start_active" id="csc-start-active" value="1">
                                <span class="csc-checkbox-text">Хочу начать принимать заказы сразу после одобрения</span>
                            </label>
                        </div>
                        <div class="csc-form-actions">
                            <button type="button" class="csc-btn csc-btn-secondary" onclick="cscRegistrationPrevStep(4)">← Назад</button>
                            <button type="submit" class="csc-btn csc-btn-success" id="csc-submit-registration">Отправить заявку ✓</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
// ============================================================
// 6. REST API: РЕГИСТРАЦИЯ МАСТЕРА
// ============================================================
add_action('rest_api_init', 'csc_register_master_registration_routes');

if (!function_exists('csc_register_master_registration_routes')) {
    function csc_register_master_registration_routes() {
        register_rest_route('csc/v1', '/register-master', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_register_master',
            'permission_callback' => '__return_true',
        ]);
        // ИСПРАВЛЕНО: Добавлен отсутствовавший маршрут для AJAX геокодирования
        register_rest_route('csc/v1', '/geocode-address', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_geocode_address',
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('csc/v1', '/master/toggle-activity', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_toggle_master_activity',
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('csc/v1', '/master/update-schedule', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_update_schedule',
            'permission_callback' => '__return_true',
        ]);
		register_rest_route('csc/v1', '/zone-check', [
            'methods'             => 'POST',
            'callback'            => 'csc_handle_zone_check',
            'permission_callback' => '__return_true',
        ]);
    }
}

/// ============================================================
// 7. ОБРАБОТЧИК РЕГИСТРАЦИИ
//    НОВОЕ 6.0: Валидация координат точки выезда и радиуса обслуживания
// ============================================================
if (!function_exists('csc_handle_register_master')) {
    function csc_handle_register_master($request) {
        $name = sanitize_text_field($request->get_param('name'));
        $phone = sanitize_text_field($request->get_param('phone'));
        $email = sanitize_email($request->get_param('email'));
        $city = sanitize_title($request->get_param('city'));
        $specializations = array_map('sanitize_text_field', (array)$request->get_param('specializations'));
        $experience_years = intval($request->get_param('experience_years'));
        $about = sanitize_textarea_field($request->get_param('about'));
        $document_type = sanitize_text_field($request->get_param('document_type'));
        $start_active = $request->get_param('start_active') === '1';
        $schedule = $request->get_param('schedule');
        
        // ИСПРАВЛЕНО 2.1: Защита от падения, если schedule не передан
        if (!is_array($schedule)) {
            $schedule = [];
        }

        // ИСПРАВЛЕНО: Серверная проверка согласия с офертой
        $accept_agreement = $request->get_param('accept_agreement');

        // === НОВОЕ 6.0: Получение параметров зоны обслуживания ===
        $service_address = sanitize_text_field($request->get_param('service_address'));
        
        // ИСПРАВЛЕНО 2.1: Защита от TypeError при передаче массива вместо строки
        $service_lat_raw = $request->get_param('service_lat');
        $service_lng_raw = $request->get_param('service_lng');
        $service_lat = is_numeric($service_lat_raw) ? floatval($service_lat_raw) : 0.0;
        $service_lng = is_numeric($service_lng_raw) ? floatval($service_lng_raw) : 0.0;
        
        $service_radius = intval($request->get_param('service_radius'));

        // Валидация
        if (empty($name) || empty($phone) || empty($email) || empty($city)) {
            return new WP_Error('bad_request', 'Заполните все обязательные поля', ['status' => 400]);
        }
        if (!$accept_agreement) {
            return new WP_Error('bad_request', 'Необходимо принять условия соглашения', ['status' => 400]);
        }
        if (!is_email($email)) {
            return new WP_Error('bad_request', 'Некорректный email', ['status' => 400]);
        }
        $cities = csc_get_cities();
        if (!isset($cities[$city]) || !$cities[$city]['active']) {
            return new WP_Error('bad_request', 'Город не найден или недоступен', ['status' => 400]);
        }

        // === НОВОЕ 6.0: ВАЛИДАЦИЯ КООРДИНАТ И РАДИУСА ===
        // Валидация радиуса: минимум 3 км, максимум 20 км, по умолчанию 10 км
        if ($service_radius < 3) $service_radius = 3;
        if ($service_radius > 20) $service_radius = 20;

        // Валидация координат
        $has_valid_coords = ($service_lat != 0 || $service_lng != 0);

        if (!$has_valid_coords) {
            // Координаты не указаны — пробуем геокодировать адрес
            if (!empty($service_address) && function_exists('csc_geocode_address')) {
                $geo_result = csc_geocode_address($service_address);
                if ($geo_result['status'] === 'ok') {
                    $service_lat = $geo_result['lat'];
                    $service_lng = $geo_result['lng'];
                    $service_address = $geo_result['display_name'];
                } else {
                    return new WP_Error('bad_request', 'Не удалось определить адрес на карте. Укажите точку выезда вручную на карте.', ['status' => 400]);
                }
            } else {
                return new WP_Error('bad_request', 'Укажите адрес проживания или точку выезда на карте', ['status' => 400]);
            }
        }
        // === КОНЕЦ ВАЛИДАЦИИ КООРДИНАТ ===
		
		// === БАЛАНС: лимит мастеров в пересекающихся зонах ===
        $capacity = csc_check_zone_capacity($city, $service_lat, $service_lng, $service_radius);
        if (!$capacity['ok']) {
            return new WP_Error('zone_limit',
                'В выбранной зоне обслуживания уже достигнуто максимальное количество мастеров (' . $capacity['limit'] . ' в ячейке ' . $capacity['cell_km'] . ' км). Укажите точку выезда в другом районе или уменьшите радиус обслуживания.',
                ['status' => 409]);
        }

        // Проверка на дубликат по телефону
        // ИСПРАВЛЕНО: Нормализация телефонов перед сравнением для предотвращения обхода проверки
        $normalized_new_phone = function_exists('csc_normalize_phone') ? csc_normalize_phone($phone) : preg_replace('/[^0-9]/', '', $phone);
        $masters = function_exists('csc_get_masters') ? csc_get_masters() : get_option('csc_masters_list', []);
        foreach ($masters as $existing_master) {
            if (isset($existing_master['phone'])) {
                $normalized_existing = function_exists('csc_normalize_phone') ? csc_normalize_phone($existing_master['phone']) : preg_replace('/[^0-9]/', '', $existing_master['phone']);
                if ($normalized_existing === $normalized_new_phone) {
                    return new WP_Error('duplicate', 'Мастер с таким телефоном уже зарегистрирован', ['status' => 409]);
                }
            }
        }

        // ИСПРАВЛЕНО 2.1: Использование нативного метода REST API для работы с файлами
        $files = $request->get_file_params();
        $photo_url = '';
        $document_url = '';
        if (!empty($files['photo'])) {
            $photo_url = csc_upload_master_file($files['photo'], 'photo', $city);
        }
        if (!empty($files['document_photo'])) {
            $document_url = csc_upload_master_file($files['document_photo'], 'document', $city);
        }
        if (empty($document_url)) {
            return new WP_Error('bad_request', 'Загрузите документ', ['status' => 400]);
        }

        // Генерация ID и токена
        $master_id = 'm_' . wp_generate_password(8, false);
        $dashboard_token = wp_generate_password(32, false);

        // Формирование расписания
        $formatted_schedule = [];
        $day_names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        foreach ($day_names as $day) {
            $formatted_schedule[$day] = [
                'enabled' => isset($schedule[$day]['enabled']) && $schedule[$day]['enabled'] === '1',
                'from' => isset($schedule[$day]['from']) ? sanitize_text_field($schedule[$day]['from']) : '09:00',
                'to' => isset($schedule[$day]['to']) ? sanitize_text_field($schedule[$day]['to']) : '18:00',
            ];
        }

                // СЕРВЕРНАЯ ВАЛИДАЦИЯ РАСПИСАНИЯ: минимум 4 часа в выбранный день
        $has_active_day = false;
        foreach ($formatted_schedule as $day_name => $day_data) {
            if (!empty($day_data['enabled'])) {
                $has_active_day = true;
                $from_ts = strtotime($day_data['from']);
                $to_ts = strtotime($day_data['to']);
                if ($from_ts === false || $to_ts === false || $to_ts <= $from_ts) {
                    return new WP_Error('bad_request', 'Некорректное время работы в расписании: ' . $day_name, ['status' => 400]);
                }
                if (($to_ts - $from_ts) < 4 * 3600) {
                    return new WP_Error('bad_request', 'Минимальная продолжительность рабочего дня — 4 часа (' . $day_name . ')', ['status' => 400]);
                }
            }
        }
        if (!$has_active_day) {
            return new WP_Error('bad_request', 'Выберите хотя бы один рабочий день', ['status' => 400]);
        }

        $settings = csc_get_registration_settings();
        $status = $settings['auto_approve'] ? 'active' : 'pending';

        // Создание мастера
        $new_master = [
            'id' => $master_id,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'city' => $city,
            'city_name' => $cities[$city]['name'],
            'specializations' => $specializations,
            'experience_years' => $experience_years,
            'about' => $about,
            'photo' => $photo_url,
            'document_type' => $document_type,
            'document_photo' => $document_url,
            'status' => $status,
            'is_online' => $start_active && $status === 'active',
            'self_registered' => true,
            'accepted_agreement' => true,
            'schedule' => $formatted_schedule,
            // === НОВОЕ 6.0: Поля зоны обслуживания ===
            'service_address' => $service_address,
            'service_lat' => $service_lat,
            'service_lng' => $service_lng,
            'service_radius' => $service_radius,
            // === КОНЕЦ ПОЛЕЙ ЗОНЫ ===
            'penalties_total' => 0,
            'penalties_amount' => 0,
            'rejections_count' => 0,
            'no_shows_count' => 0,
            'consecutive_completed' => 0, // для прощающего механизма
            'forgiven_rejections' => 0,
            'forgiven_no_shows' => 0,
            'dashboard_token' => $dashboard_token,
            'rating' => 0,
            'total_reviews' => 0,
            'created_at' => current_time('mysql'),
            'approved_at' => $status === 'active' ? current_time('mysql') : null,
            'rejection_reason' => '',
        ];

        $masters[$master_id] = $new_master;
        update_option('csc_masters_list', $masters, false);
        if (function_exists('csc_cache_delete_masters')) {
            csc_cache_delete_masters();
        }

        // Уведомления
        csc_send_registration_notifications($new_master, $settings);

        return new WP_REST_Response([
            'success' => true,
            'message' => $status === 'active'
                ? 'Вы успешно зарегистрированы и активированы!'
                : 'Ваша заявка отправлена на модерацию',
            'data' => [
                'master_id' => $master_id,
                'status' => $status,
                'auto_approved' => $settings['auto_approve'],
            ],
        ], 200);
    }
}
// ============================================================
// 7.1 НОВОЕ 6.0: AJAX ГЕОКОДИРОВАНИЕ АДРЕСА
//     Вызывается из формы регистрации для предпросмотра на карте
// ============================================================
if (!function_exists('csc_handle_geocode_address')) {
    function csc_handle_geocode_address($request) {
        $address = sanitize_text_field($request->get_param('address'));

        if (empty($address)) {
            return new WP_Error('bad_request', 'Укажите адрес для поиска', ['status' => 400]);
        }

        if (!function_exists('csc_geocode_address')) {
            return new WP_Error('internal', 'Модуль геокодирования недоступен', ['status' => 500]);
        }

        $result = csc_geocode_address($address);

        if ($result['status'] === 'ok') {
            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'lat' => $result['lat'],
                    'lng' => $result['lng'],
                    'display_name' => $result['display_name'],
                ],
            ], 200);
        }

        return new WP_REST_Response([
            'success' => false,
            'message' => 'Адрес не найден. Укажите точку на карте вручную.',
            'data' => null,
        ], 200);
    }
}

// ============================================================
// 7.2 НОВОЕ 6.0: МИГРАЦИЯ СУЩЕСТВУЮЩИХ МАСТЕРОВ
//     Добавляет дефолтные поля зоны обслуживания старым мастерам
// ============================================================
if (!function_exists('csc_migrate_masters_service_fields')) {
    function csc_migrate_masters_service_fields() {
        $migrated_flag = get_option('csc_masters_service_fields_migrated', false);
        if ($migrated_flag) return;

        $masters = get_option('csc_masters_list', []);
        if (empty($masters)) {
            update_option('csc_masters_service_fields_migrated', true, false);
            return;
        }

        $need_save = false;
        foreach ($masters as $mid => &$master) {
            if (!isset($master['service_address'])) {
                $master['service_address'] = '';
                $need_save = true;
            }
            if (!isset($master['service_lat'])) {
                $master['service_lat'] = 0;
                $need_save = true;
            }
            if (!isset($master['service_lng'])) {
                $master['service_lng'] = 0;
                $need_save = true;
            }
            if (!isset($master['service_radius'])) {
                $master['service_radius'] = 10; // Дефолтный радиус 10 км
                $need_save = true;
            }
        }
        unset($master);

        if ($need_save) {
            update_option('csc_masters_list', $masters, false);
        }

        update_option('csc_masters_service_fields_migrated', true, false);
    }
}
add_action('admin_init', 'csc_migrate_masters_service_fields', 5);

// ============================================================
// 8. ЗАГРУЗКА ФАЙЛОВ МАСТЕРА
// ============================================================
if (!function_exists('csc_upload_master_file')) {
    function csc_upload_master_file($file, $type, $city) {
        if ($file['error'] !== UPLOAD_ERR_OK) return '';
        
        $max_size = 10 * 1024 * 1024; // 10 MB
        if ($file['size'] > $max_size) return '';
        
        // ИСПРАВЛЕНО: Безопасная загрузка через wp_handle_upload с проверкой MIME-типа
        if (!function_exists('wp_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }

        // ИСПРАВЛЕНО 2.1: Сохраняем ссылку на замыкание, чтобы корректно удалить ТОЛЬКО этот фильтр
        $csc_upload_dir_filter = function($uploads) use ($city, $type) {
            $uploads['subdir'] = '/csc-uploads/masters/' . $city . '/' . $type;
            $uploads['path'] = $uploads['basedir'] . $uploads['subdir'];
            $uploads['url'] = $uploads['baseurl'] . $uploads['subdir'];
            return $uploads;
        };

        add_filter('upload_dir', $csc_upload_dir_filter);

        $upload_overrides = [
            'test_form' => false,
            'mimes' => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif' => 'image/gif',
                'png' => 'image/png',
                'webp' => 'image/webp',
                'heic' => 'image/heic',
            ]
        ];

        $movefile = wp_handle_upload($file, $upload_overrides);

        // ИСПРАВЛЕНО 2.1: Точечное удаление фильтра вместо remove_all_filters
        remove_filter('upload_dir', $csc_upload_dir_filter);

        if (isset($movefile['error'])) {
            error_log('[CSC_UPLOAD] Error: ' . $movefile['error']);
            return '';
        }

        // Дополнительная проверка MIME-типа через finfo
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $movefile['file']);
            finfo_close($finfo);
            $allowed_mimes = ['image/jpeg', 'image/gif', 'image/png', 'image/webp', 'image/heic'];
            if (!in_array($mime, $allowed_mimes)) {
                @unlink($movefile['file']);
                return '';
            }
        }

        return $movefile['url'];
    }
}
// ============================================================
// 9. УВЕДОМЛЕНИЯ ПРИ РЕГИСТРАЦИИ
// ============================================================
if (!function_exists('csc_send_registration_notifications')) {
    function csc_send_registration_notifications($master, $settings) {
        $admin_email = get_option('admin_email');
        
        // Админу о новой заявке
        if (!$settings['auto_approve']) {
            $subject = '🆕 Новая заявка мастера: ' . $master['name'];
            $message = "Получена новая заявка на регистрацию мастера.\n";
            $message .= "ФИО: {$master['name']}\n";
            $message .= "Телефон: {$master['phone']}\n";
            $message .= "Email: {$master['email']}\n";
            $message .= "Город: {$master['city_name']}\n";
            $message .= "Специализации: " . implode(', ', $master['specializations']) . "\n";
            $message .= "Перейдите в админку для модерации.";
            wp_mail($admin_email, $subject, $message);
        }
        
        // Мастеру об одобрении
        if ($settings['auto_approve']) {
            $dashboard_url = home_url('/?csc_master_dashboard=1&mid=' . urlencode($master['id']) . '&token=' . urlencode($master['dashboard_token']));
            $subject = '✅ Вы зарегистрированы как мастер!';
            $message = "Здравствуйте, {$master['name']}!\n";
            $message .= "Ваша заявка на регистрацию успешно одобрена.\n";
            $message .= "Вы можете начать принимать заказы.\n";
            $message .= "Ссылка на личный кабинет:\n{$dashboard_url}\n";
            $message .= "Сохраните эту ссылку - она нужна для входа.";
            wp_mail($master['email'], $subject, $message);
        }
        
        // Логирование
        if (function_exists('csc_log_notification')) {
            csc_log_notification('', 'admin', 'admin', 'web',
                '🆕 Новый мастер',
                "Мастер {$master['name']} подал заявку на регистрацию ({$master['city_name']})",
                ['master_id' => $master['id'], 'type' => 'new_master']
            );
        }
    }
}

// ============================================================
// 10. ПЕРЕКЛЮЧЕНИЕ АКТИВНОСТИ МАСТЕРА
// ============================================================
if (!function_exists('csc_handle_toggle_master_activity')) {
    function csc_handle_toggle_master_activity($request) {
        // ИСПРАВЛЕНО 2.1: wp_verify_nonce УДАЛЕН. 
        // Мастера авторизуются по токену, а не через сессию WP. wp_verify_nonce всегда возвращал false для гостей.
        // Безопасность теперь полностью обеспечивается функцией csc_verify_master_auth ниже.

        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;
        
        $mid = $auth['mid'];
        $masters = $auth['masters'];
        
        if (!isset($masters[$mid])) {
            return new WP_Error('not_found', 'Мастер не найден', ['status' => 404]);
        }
        
        // Проверка статуса модерации
        $current_status = $masters[$mid]['status'] ?? 'pending';
        if ($current_status === 'pending') {
            return new WP_Error('pending', 'Заявка ещё на модерации. Дождитесь одобрения.', ['status' => 403]);
        }
        if ($current_status === 'blocked') {
            return new WP_Error('blocked', 'Аккаунт заблокирован из-за штрафов. Свяжитесь с администрацией.', ['status' => 403]);
        }
        if ($current_status === 'rejected') {
            return new WP_Error('rejected', 'Заявка была отклонена. Обратитесь к администрации.', ['status' => 403]);
        }
        
        // Переключение
        $new_is_online = !$masters[$mid]['is_online'];
        $masters[$mid]['is_online'] = $new_is_online;
        $masters[$mid]['status'] = $new_is_online ? 'active' : 'inactive';
        
        update_option('csc_masters_list', $masters, false);
        if (function_exists('csc_cache_delete_masters')) {
            csc_cache_delete_masters();
        }
        
        // Логирование
        if (function_exists('csc_log_notification')) {
            csc_log_notification('', 'master', $mid, 'web',
                $new_is_online ? '🟢 Мастер активировался' : '🔴 Мастер деактивировался',
                "Статус изменён на " . ($new_is_online ? 'активен' : 'неактивен'),
                ['type' => 'activity_toggle']
            );
        }
        
        return new WP_REST_Response([
            'success' => true,
            'message' => $new_is_online
                ? 'Вы теперь активны и принимаете заказы'
                : 'Вы деактивированы и не будете получать новые заказы',
            'data' => [
                'is_online' => $new_is_online,
                'status' => $masters[$mid]['status']
            ],
        ], 200);
    }
}
// ============================================================
// 11. ОБНОВЛЕНИЕ РАСПИСАНИЯ
//     НОВОЕ 2.0: преобразование schedule в work_days + work_hours_start/end
// ============================================================
if (!function_exists('csc_handle_update_schedule')) {
    function csc_handle_update_schedule($request) {
        // ИСПРАВЛЕНО 2.1: wp_verify_nonce УДАЛЕН (мастера не используют WP сессию).
        // Проверка токена происходит внутри csc_verify_master_auth.

        $auth = csc_verify_master_auth($request);
        if (is_wp_error($auth)) return $auth;
        
        $mid = $auth['mid'];
        $masters = $auth['masters'];
        
        if (!isset($masters[$mid])) {
            return new WP_Error('not_found', 'Мастер не найден', ['status' => 404]);
        }
        
        $schedule = $request->get_param('schedule');
        if (empty($schedule) || !is_array($schedule)) {
            return new WP_Error('bad_request', 'Расписание не передано', ['status' => 400]);
        }
        
        $day_names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $formatted_schedule = [];
        foreach ($day_names as $day) {
            $formatted_schedule[$day] = [
                'enabled' => isset($schedule[$day]['enabled']) && $schedule[$day]['enabled'] === '1',
                'from' => isset($schedule[$day]['from']) ? sanitize_text_field($schedule[$day]['from']) : '09:00',
                'to' => isset($schedule[$day]['to']) ? sanitize_text_field($schedule[$day]['to']) : '18:00',
            ];
        }
        
        $masters[$mid]['schedule'] = $formatted_schedule;
        
        // === ШАГ 1: ВАЛИДАЦИЯ И СБОР ДАННЫХ ===
        $schedule = $request->get_param('schedule');
        if (!is_array($schedule) || empty($schedule)) {
            return new WP_Error('csc_invalid_schedule', 'Расписание обязательно для заполнения.', ['status' => 400]);
        }

        $valid_days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $day_map = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
        ];

        $enabled_days_count = 0;
        $total_hours_per_week = 0;
        $work_days = [];
        $min_start_hour = 23;
        $max_end_hour = 0;

        foreach ($schedule as $day_key => $day_data) {
            // Проверка ключа дня
            if (!in_array($day_key, $valid_days, true)) {
                return new WP_Error('csc_invalid_day', "Некорректный день недели: {$day_key}", ['status' => 400]);
            }

            if (!is_array($day_data)) {
                return new WP_Error('csc_invalid_day_data', "Некорректные данные дня: {$day_key}", ['status' => 400]);
            }

            // Пропускаем выключенные дни
            if (empty($day_data['enabled'])) {
                continue;
            }

            $from = $day_data['from'] ?? '';
            $to = $day_data['to'] ?? '';

            // Валидация формата HH:MM с одновременным извлечением часов и минут
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $from, $from_matches)) {
                return new WP_Error('csc_invalid_from', "Некорректное время начала для {$day_key}: {$from}", ['status' => 400]);
            }
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $to, $to_matches)) {
                return new WP_Error('csc_invalid_to', "Некорректное время окончания для {$day_key}: {$to}", ['status' => 400]);
            }

            // from должно быть строго меньше to (строковое сравнение работает для формата HH:MM)
            if ($from >= $to) {
                return new WP_Error('csc_invalid_range', "Время начала должно быть раньше времени окончания для {$day_key}", ['status' => 400]);
            }

            // Расчет длительности смены
            $from_minutes = ((int)$from_matches[1] * 60) + (int)$from_matches[2];
            $to_minutes = ((int)$to_matches[1] * 60) + (int)$to_matches[2];
            $diff_minutes = $to_minutes - $from_minutes;

            // ИСПРАВЛЕНО: минимум 4 часа в день (240 минут).
            if ($diff_minutes < 240) {
                return new WP_Error('csc_short_day', "Минимальная длительность рабочего дня: 4 часа ({$day_key})", ['status' => 400]);
            }

            // === ШАГ 2: НАКОПЛЕНИЕ ДАННЫХ ДЛЯ СОХРАНЕНИЯ (внутри того же цикла) ===
            $enabled_days_count++;
            $total_hours_per_week += ($diff_minutes / 60);
            
            if (isset($day_map[$day_key])) {
                $work_days[] = $day_map[$day_key];
            }

            $from_h = (int)$from_matches[1];
            $to_h = (int)$to_matches[1];
            
            if ($from_h < $min_start_hour) $min_start_hour = $from_h;
            if ($to_h > $max_end_hour) $max_end_hour = $to_h;
        }

        // Проверка глобальных ограничений
        if ($enabled_days_count < 2) {
            return new WP_Error('csc_few_days', 'Минимум 2 рабочих дня в неделю.', ['status' => 400]);
        }

        if ($total_hours_per_week < 10) {
            return new WP_Error('csc_few_hours', 'Минимум 10 рабочих часов в неделю.', ['status' => 400]);
        }

        // === ШАГ 3: ФИНАЛИЗАЦИЯ И СОХРАНЕНИЕ ===
        sort($work_days);

        // Предполагается, что $mid и массив $masters уже получены и определены выше в коде
        $masters[$mid]['schedule'] = $schedule; // Сохраняем детальное расписание
        $masters[$mid]['work_days'] = $work_days;
        $masters[$mid]['work_hours_start'] = $min_start_hour < 23 ? $min_start_hour : 9;
        $masters[$mid]['work_hours_end'] = $max_end_hour > 0 ? $max_end_hour : 18;

        update_option('csc_masters_list', $masters, false);

        if (function_exists('csc_cache_delete_masters')) {
            csc_cache_delete_masters();
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Расписание обновлено',
            'data' => ['schedule' => $schedule],
        ], 200);
	}
}

// ============================================================
// 12. ASSETS ДЛЯ ФОРМЫ РЕГИСТРАЦИИ
// ============================================================
if (!function_exists('csc_register_registration_assets')) {
    function csc_register_registration_assets() {
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $plugin_dir = plugin_dir_path(dirname(__FILE__));
        
        $css_path = $plugin_dir . 'assets/css/master-registration.css';
        $css_url = $plugin_url . 'assets/css/master-registration.css';
        $css_version = file_exists($css_path) ? filemtime($css_path) : '1.0';
        wp_register_style('csc-master-registration', $css_url, [], $css_version);
        
        $js_path = $plugin_dir . 'assets/js/master-registration.js';
        $js_url = $plugin_url . 'assets/js/master-registration.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : '1.0';
        wp_register_script('csc-master-registration', $js_url, [], $js_version, true);
    }
}
add_action('init', 'csc_register_registration_assets');

// ============================================================
// 13. АДМИНКА: СТРАНИЦА НАСТРОЕК РЕГИСТРАЦИИ
//     ИСПРАВЛЕНО: перенесено в меню "Настройки" как подменю калькулятора
// ============================================================
if (!function_exists('csc_add_registration_settings_page')) {
    function csc_add_registration_settings_page() {
        add_submenu_page(
            'options-general.php',     // родитель: стандартное меню "Настройки"
            'Регистрация мастеров',    // title страницы
            'Регистрация мастеров',    // title в меню
            'manage_options',          // capability
            'csc-master-registration', // slug (сохраняем прежний, чтобы ссылки работали)
            'csc_render_registration_settings_page'
        );
    }
}
// Приоритет 20 — после других подменю плагина
add_action('admin_menu', 'csc_add_registration_settings_page', 20);

if (!function_exists('csc_render_registration_settings_page')) {
    function csc_render_registration_settings_page() {
        if (isset($_POST['csc_save_registration_settings'])) {
            check_admin_referer('csc_save_registration_settings', 'csc_registration_nonce');
            $settings = [
                'auto_approve' => isset($_POST['auto_approve']) && $_POST['auto_approve'] === '1',
                'penalty_rejection_amount' => floatval($_POST['penalty_rejection_amount'] ?? 500),
                'penalty_rejection_type' => sanitize_text_field($_POST['penalty_rejection_type'] ?? 'fixed'),
                'penalty_rejection_percent' => floatval($_POST['penalty_rejection_percent'] ?? 10),
                'penalty_no_show_amount' => floatval($_POST['penalty_no_show_amount'] ?? 1000),
                'penalty_no_show_type' => sanitize_text_field($_POST['penalty_no_show_type'] ?? 'fixed'),
                'penalty_no_show_percent' => floatval($_POST['penalty_no_show_percent'] ?? 20),
                'penalty_late_amount' => floatval($_POST['penalty_late_amount'] ?? 300),
                'penalty_late_type' => sanitize_text_field($_POST['penalty_late_type'] ?? 'fixed'),
                'penalty_late_percent' => floatval($_POST['penalty_late_percent'] ?? 5),
                'max_rejections_before_block' => intval($_POST['max_rejections_before_block'] ?? 5),
                'forgiveness_ratio_rejection' => intval($_POST['forgiveness_ratio_rejection'] ?? 4),
                'forgiveness_ratio_no_show' => intval($_POST['forgiveness_ratio_no_show'] ?? 2),
                'block_penalty_amount' => floatval($_POST['block_penalty_amount'] ?? 5000),
				'zone_max_masters' => intval($_POST['zone_max_masters'] ?? 0),
                'zone_cell_km' => max(0.5, floatval($_POST['zone_cell_km'] ?? 2)),
                'notify_admin_on_rejection' => isset($_POST['notify_admin_on_rejection']) && $_POST['notify_admin_on_rejection'] === '1',
                'notify_master_on_rejection' => isset($_POST['notify_master_on_rejection']) && $_POST['notify_master_on_rejection'] === '1',
                'notify_admin_on_block' => isset($_POST['notify_admin_on_block']) && $_POST['notify_admin_on_block'] === '1',
                'notify_master_on_block' => isset($_POST['notify_master_on_block']) && $_POST['notify_master_on_block'] === '1',
            ];
            update_option('csc_master_registration_settings', $settings, false);
            // Сохранение оферты
            if (isset($_POST['agreement_text'])) {
                update_option('csc_master_agreement_text', wp_kses_post($_POST['agreement_text']), false);
            }
            echo '<div class="notice notice-success"><p>Настройки сохранены</p></div>';
        }
        
        $settings = csc_get_registration_settings();
        $agreement_text = csc_get_agreement_text();
        ?>
        <div class="wrap">
            <h1>Настройки регистрации мастеров</h1>
            
            <form method="post">
                <?php wp_nonce_field('csc_save_registration_settings', 'csc_registration_nonce'); ?>
                
                <h2>Модерация</h2>
                <table class="form-table">
                    <tr>
                        <th>Автоматическое одобрение</th>
                        <td>
                            <label>
                                <input type="checkbox" name="auto_approve" value="1" <?php checked($settings['auto_approve'], true); ?>>
                                Одобрять заявки автоматически без модерации
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2>Штрафы</h2>
                <table class="form-table">
                    <tr>
                        <th>Штраф за отказ от заявки</th>
                        <td>
                            <select name="penalty_rejection_type">
                                <option value="fixed" <?php selected($settings['penalty_rejection_type'], 'fixed'); ?>>Фиксированная сумма</option>
                                <option value="percent" <?php selected($settings['penalty_rejection_type'], 'percent'); ?>>% от стоимости заказа</option>
                            </select>
                            <input type="number" name="penalty_rejection_amount" value="<?php echo $settings['penalty_rejection_amount']; ?>" step="0.01" style="width:100px;"> ₽
                            <input type="number" name="penalty_rejection_percent" value="<?php echo $settings['penalty_rejection_percent']; ?>" step="0.1" style="width:80px;"> %
                        </td>
                    </tr>
                    <tr>
                        <th>Штраф за невыполнение</th>
                        <td>
                            <select name="penalty_no_show_type">
                                <option value="fixed" <?php selected($settings['penalty_no_show_type'], 'fixed'); ?>>Фиксированная сумма</option>
                                <option value="percent" <?php selected($settings['penalty_no_show_type'], 'percent'); ?>>% от стоимости заказа</option>
                            </select>
                            <input type="number" name="penalty_no_show_amount" value="<?php echo $settings['penalty_no_show_amount']; ?>" step="0.01" style="width:100px;"> ₽
                            <input type="number" name="penalty_no_show_percent" value="<?php echo $settings['penalty_no_show_percent']; ?>" step="0.1" style="width:80px;"> %
                        </td>
                    </tr>
                    <tr>
                        <th>Штраф за опоздание</th>
                        <td>
                            <select name="penalty_late_type">
                                <option value="fixed" <?php selected($settings['penalty_late_type'], 'fixed'); ?>>Фиксированная сумма</option>
                                <option value="percent" <?php selected($settings['penalty_late_type'], 'percent'); ?>>% от стоимости заказа</option>
                            </select>
                            <input type="number" name="penalty_late_amount" value="<?php echo $settings['penalty_late_amount']; ?>" step="0.01" style="width:100px;"> ₽
                            <input type="number" name="penalty_late_percent" value="<?php echo $settings['penalty_late_percent']; ?>" step="0.1" style="width:80px;"> %
                        </td>
                    </tr>
                </table>
                
                <h2>Лимиты и блокировка</h2>
                <table class="form-table">
                    <tr>
                        <th>Максимум отказов до блокировки</th>
                        <td><input type="number" name="max_rejections_before_block" value="<?php echo $settings['max_rejections_before_block']; ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th>Прощающий коэффициент (отказы)</th>
                        <td>
                            <input type="number" name="forgiveness_ratio_rejection" value="<?php echo $settings['forgiveness_ratio_rejection']; ?>" min="1">
                            выполненных подряд = 1 прощённый отказ
                        </td>
                    </tr>
                    <tr>
                        <th>Прощающий коэффициент (невыходы)</th>
                        <td>
                            <input type="number" name="forgiveness_ratio_no_show" value="<?php echo $settings['forgiveness_ratio_no_show']; ?>" min="1">
                            выполненных подряд = 1 прощённый невыход
                        </td>
                    </tr>
                    <tr>
                        <th>Сумма штрафов для блокировки</th>
                        <td><input type="number" name="block_penalty_amount" value="<?php echo $settings['block_penalty_amount']; ?>" step="0.01"> ₽</td>
                    </tr>
                </table>
                
                <h2>Уведомления</h2>
                <table class="form-table">
                    <tr>
                        <th>Уведомлять админа об отказе</th>
                        <td><label><input type="checkbox" name="notify_admin_on_rejection" value="1" <?php checked($settings['notify_admin_on_rejection'], true); ?>></label></td>
                    </tr>
                    <tr>
                        <th>Уведомлять мастера об отказе</th>
                        <td><label><input type="checkbox" name="notify_master_on_rejection" value="1" <?php checked($settings['notify_master_on_rejection'], true); ?>></label></td>
                    </tr>
                    <tr>
                        <th>Уведомлять админа о блокировке</th>
                        <td><label><input type="checkbox" name="notify_admin_on_block" value="1" <?php checked($settings['notify_admin_on_block'], true); ?>></label></td>
                    </tr>
                    <tr>
                        <th>Уведомлять мастера о блокировке</th>
                        <td><label><input type="checkbox" name="notify_master_on_block" value="1" <?php checked($settings['notify_master_on_block'], true); ?>></label></td>
                    </tr>
                </table>
                
				<h2>Баланс спроса и предложения</h2>
                <table class="form-table">
                    <tr>
                        <th>Максимум мастеров в ячейке зоны</th>
                        <td>
                            <input type="number" name="zone_max_masters" value="<?php echo intval($settings['zone_max_masters']); ?>" min="0">
                            <p class="description">0 — контроль выключен. Например 3: в каждой ячейке зоны максимум 3 мастера с пересекающимися зонами обслуживания. При превышении новая заявка отклоняется.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Размер ячейки зоны, км</th>
                        <td>
                            <input type="number" name="zone_cell_km" value="<?php echo floatval($settings['zone_cell_km']); ?>" min="0.5" step="0.5">
                            <p class="description">Чем меньше ячейка, тем точнее контроль пересечений (рекомендуется 1–3 км).</p>
                        </td>
                    </tr>
                </table>
				
                <h2>Текст соглашения</h2>
                <textarea name="agreement_text" rows="20" style="width:100%;"><?php echo esc_textarea($agreement_text); ?></textarea>
                <p><small>Можно использовать HTML теги</small></p>
                
                <p class="submit">
                    <button type="submit" name="csc_save_registration_settings" class="button button-primary">Сохранить настройки</button>
                </p>
            </form>
        </div>
        <?php
    }
}
// ============================================================
// 16. СЕРВЕРНЫЙ ПРОКСИ ДЛЯ ТАЙЛОВ КАРТЫ
//     Обходит блокировку провайдера, кэширует тайлы на 30 дней
// ============================================================
add_action('rest_api_init', function() {
    register_rest_route('csc/v1', '/map-tile/(?P<z>\d+)/(?P<x>\d+)/(?P<y>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'csc_serve_map_tile',
        'permission_callback' => '__return_true',
    ]);
});

if (!function_exists('csc_serve_map_tile')) {
    function csc_serve_map_tile($request) {
        $z = intval($request['z']);
        $x = intval($request['x']);
        $y = intval($request['y']);
        
        // Защита от мусорных значений
        if ($z < 0 || $z > 19 || $x < 0 || $y < 0) {
            return new WP_Error('bad_tile', 'Invalid tile', ['status' => 400]);
        }
        
        $upload_dir = wp_upload_dir();
        $cache_dir = $upload_dir['basedir'] . '/csc-uploads/tiles/' . $z;
        $cache_file = $cache_dir . '/' . $x . '_' . $y . '.png';
        
        // Отдаём из кэша если есть и моложе 30 дней
        if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 30 * DAY_IN_SECONDS) {
            header('Content-Type: image/png');
            header('Cache-Control: public, max-age=2592000');
            readfile($cache_file);
            exit;
        }
        
        // Ротация поддоменов a/b/c для распределения нагрузки
        $subdomains = ['a', 'b', 'c'];
        $subdomain = $subdomains[($x + $y) % 3];
        $url = "https://{$subdomain}.tile.openstreetmap.org/{$z}/{$x}/{$y}.png";
        
        $response = wp_remote_get($url, [
            'timeout' => 8,
            'headers' => [
                'User-Agent' => 'CustomServiceCalculator/2.0 (WordPress Plugin)',
            ],
        ]);
        
        if (is_wp_error($response)) {
            error_log('[CSC_TILE] Error: ' . $response->get_error_message());
            return new WP_Error('tile_error', 'Cannot load tile', ['status' => 502]);
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[CSC_TILE] Tile server returned: ' . $code);
            return new WP_Error('tile_error', 'Tile server error: ' . $code, ['status' => 502]);
        }
        
        $body = wp_remote_retrieve_body($response);
        
        // Кэшируем на диск
        if (!file_exists($cache_dir)) {
            wp_mkdir_p($cache_dir);
        }
        @file_put_contents($cache_file, $body);
        
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=2592000');
        echo $body;
        exit;
    }
}
// ============================================================
// 17. ОБРАТНОЕ ГЕОКОДИРОВАНИЕ: координаты точки → адрес
//     Автоматически заполняет поле адреса при клике на карту
// ============================================================
add_action('rest_api_init', function() {
    register_rest_route('csc/v1', '/reverse-geocode', [
        'methods'             => 'POST',
        'callback'            => 'csc_handle_reverse_geocode',
        'permission_callback' => '__return_true',
    ]);
});

if (!function_exists('csc_handle_reverse_geocode')) {
    function csc_handle_reverse_geocode($request) {
        $lat = floatval($request->get_param('lat'));
        $lng = floatval($request->get_param('lng'));
        
        if ($lat == 0 && $lng == 0) {
            return new WP_REST_Response(['success' => false, 'address' => ''], 200);
        }
        
        // Кэш по координатам
        $cache_key = 'csc_revgeo_' . md5(number_format($lat, 6) . ',' . number_format($lng, 6));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new WP_REST_Response([
                'success' => true,
                'address' => $cached,
                'from_cache' => true,
            ], 200);
        }
        
        $url = add_query_arg([
            'format'          => 'json',
            'lat'             => $lat,
            'lon'             => $lng,
            'zoom'            => 18, // максимальная детализация: дом, улица
            'addressdetails'  => 1,
            'accept-language' => 'ru',
        ], 'https://nominatim.openstreetmap.org/reverse');
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'User-Agent' => 'CustomServiceCalculator/5.1 (WordPress Plugin)',
            ],
        ]);
        
        if (is_wp_error($response)) {
            error_log('[CSC_REVGEO] Network error: ' . $response->get_error_message());
            return new WP_REST_Response(['success' => false, 'address' => ''], 200);
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_REST_Response(['success' => false, 'address' => ''], 200);
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($body) || !isset($body['display_name'])) {
            error_log('[CSC_REVGEO] No address for: ' . $lat . ',' . $lng);
            return new WP_REST_Response(['success' => false, 'address' => ''], 200);
        }
        
        $address = sanitize_text_field($body['display_name']);
        
        // Кэш на 90 дней
        set_transient($cache_key, $address, 90 * DAY_IN_SECONDS);
        
        return new WP_REST_Response([
            'success' => true,
            'address' => $address,
        ], 200);
    }
}
// ============================================================
// 18. БАЛАНС СПРОСА И ПРЕДЛОЖЕНИЯ: ЛИМИТ МАСТЕРОВ В ПЕРЕСЕКАЮЩИХСЯ ЗОНАХ
//     Метод геосетки: зона мастера покрывает ячейки, в каждой ячейке
//     не больше zone_max_masters мастеров
// ============================================================
// ИСПРАВЛЕНО: дубль функции удалён — единая реализация находится в
// includes/csc-core-functions.php (загружается раньше этого файла).
// Оставлена только заглушка на случай прямой загрузки модуля вне ядра.
if (!function_exists('csc_haversine_km')) {
    function csc_haversine_km($lat1, $lon1, $lat2, $lon2) {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

if (!function_exists('csc_get_covered_cells')) {
    function csc_get_covered_cells($lat, $lng, $radius_km, $cell_km) {
        $cell_km = max(0.5, floatval($cell_km));
        $latStep = $cell_km / 111.32;
        $cosLat  = max(0.01, cos(deg2rad($lat)));
        $lngStep = $cell_km / (111.32 * $cosLat);

        $cells  = [];
        $minLatI = floor(($lat - $radius_km / 111.32) / $latStep);
        $maxLatI = floor(($lat + $radius_km / 111.32) / $latStep);
        $minLngI = floor(($lng - $radius_km / (111.32 * $cosLat)) / $lngStep);
        $maxLngI = floor(($lng + $radius_km / (111.32 * $cosLat)) / $lngStep);

        for ($la = $minLatI; $la <= $maxLatI; $la++) {
            for ($lo = $minLngI; $lo <= $maxLngI; $lo++) {
                $cLat = ($la + 0.5) * $latStep;
                $cLng = ($lo + 0.5) * $lngStep;
                if (csc_haversine_km($lat, $lng, $cLat, $cLng) <= $radius_km) {
                    $cells[] = $la . ':' . $lo;
                }
            }
        }
        return $cells;
    }
}

if (!function_exists('csc_check_zone_capacity')) {
    function csc_check_zone_capacity($city, $lat, $lng, $radius_km, $exclude_master_id = null) {
        $settings = csc_get_registration_settings();
        $limit = intval($settings['zone_max_masters']);

        // Персональный лимит города перекрывает общий
        $cities = csc_get_cities();
        if (isset($cities[$city]['zone_limit']) && intval($cities[$city]['zone_limit']) > 0) {
            $limit = intval($cities[$city]['zone_limit']);
        }

        if ($limit <= 0) {
            return ['ok' => true, 'limit' => 0, 'max_current' => 0, 'cell_km' => 0];
        }

        $cell_km = max(0.5, floatval($settings['zone_cell_km']));

        $masters = function_exists('csc_get_masters') ? csc_get_masters() : get_option('csc_masters_list', []);

        // Учитываются только "живые" мастера города: active и pending
        $active = [];
        foreach ($masters as $mid => $m) {
            if ($exclude_master_id && $mid === $exclude_master_id) continue;
            if (($m['city'] ?? '') !== $city) continue;
            if (!in_array($m['status'] ?? '', ['active', 'pending'], true)) continue;
            if (empty($m['service_lat']) || empty($m['service_lng'])) continue;
            $active[] = $m;
        }

        $new_cells = csc_get_covered_cells($lat, $lng, $radius_km, $cell_km);
        if (empty($new_cells)) {
            return ['ok' => true, 'limit' => $limit, 'max_current' => 0, 'cell_km' => $cell_km];
        }

        $counts = array_fill_keys($new_cells, 0);
        foreach ($active as $m) {
            $m_cells = csc_get_covered_cells(
                floatval($m['service_lat']),
                floatval($m['service_lng']),
                intval($m['service_radius']),
                $cell_km
            );
            foreach ($m_cells as $c) {
                if (isset($counts[$c])) $counts[$c]++;
            }
        }

        $max_current = max($counts);

        return [
            'ok'          => $max_current < $limit,
            'limit'       => $limit,
            'max_current' => $max_current,
            'cell_km'     => $cell_km,
        ];
    }
}

// ============================================================
// 18.1 REST-ОБРАБОТЧИК LIVE-ПРОВЕРКИ ЗАГРУЗКИ ЗОНЫ
// ============================================================
if (!function_exists('csc_handle_zone_check')) {
    function csc_handle_zone_check($request) {
        $city   = sanitize_title($request->get_param('city'));
        $lat    = floatval($request->get_param('lat'));
        $lng    = floatval($request->get_param('lng'));
        $radius = intval($request->get_param('radius'));

        $cap = csc_check_zone_capacity($city, $lat, $lng, $radius);

        return new WP_REST_Response(['success' => true, 'data' => $cap], 200);
    }
}
// ============================================================
// 19. МИГРАЦИЯ: добавление zone_limit существующим городам
// ============================================================
// ИСПРАВЛЕНО: дубль функции и повторная регистрация хука удалены —
// единая реализация csc_migrate_cities_zone_limit() с регистрацией на
// admin_init находится в includes/csc-cities-settings.php (загружается из ядра).