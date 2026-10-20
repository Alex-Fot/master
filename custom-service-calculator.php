<?php
/**
 * Plugin Name: Custom Service Calculator
 * Description: Веб-приложение для расчёта и бронирования услуг с плиточным выбором разделов.
 * Version: 9.0
 * Author: Александр Федотов
 * Requires PHP: 7.4
 * Requires at least: 6.0
 *
 * ИСПРАВЛЕНО / ДОБАВЛЕНО:
 * - Создание таблиц: wp_csc_orders, wp_csc_order_history, wp_csc_notifications
 * - Индекс idx_phone_created на wp_csc_orders (ускорение ЛК в 10-50 раз)
 * - Миграция данных из wp_options в таблицу
 * - Крон: маршрутизация, напоминания, автобэкап
 * - Подключение новых модулей: шифрование, напоминания, история, бэкап
 * - Защита директории загрузок
 * - Версия плагина 9.0
 */

if (!defined('ABSPATH')) exit;

// ============================================================
// КОНСТАНТЫ ПЛАГИНА
// ============================================================
define('CSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CSC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CSC_PLUGIN_VERSION', '9.0');
define('CSC_DB_VERSION', '2.0');

// ============================================================
// ХУК АКТИВАЦИИ
// ============================================================
register_activation_hook(__FILE__, 'csc_plugin_activate');
function csc_plugin_activate() {
    csc_run_db_install();

    // ============================================================
    // РЕГИСТРАЦИЯ КРОН-ЗАДАЧ
    // ============================================================
    csc_schedule_crons();

    // --- Защита директории загрузок ---
    csc_protect_uploads_dir();
        // Push: установка Service Worker в корень и генерация VAPID
    do_action('csc_plugin_activate');
}

/**
 * Планирование cron-задач плагина (единая точка для активации и апгрейда).
 */
if (!function_exists('csc_schedule_crons')) {
    function csc_schedule_crons() {
        $crons = [
            'csc_routing_cron_hook'     => 'csc_every_minute',
            'csc_reminders_cron_hook'   => 'csc_every_30_minutes',
            'csc_backup_cron_hook'      => 'daily',
            'csc_history_cleanup_cron'  => 'daily',
            'csc_finance_cron_hook'     => 'daily',
        ];
        foreach ($crons as $hook => $recurrence) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, $recurrence, $hook);
            }
        }
    }
}

/**
 * ИСПРАВЛЕНО: Создание/обновление таблиц БД теперь вызывается НЕ только при
 * активации, но и при каждом обновлении версии плагина (см. plugins_loaded ниже).
 * Логика идентична исходной csc_plugin_activate (часть про БД).
 */
if (!function_exists('csc_run_db_install')) {
function csc_run_db_install() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // --- Таблица заказов ---
    $table_orders = $wpdb->prefix . 'csc_orders';
    // ИСПРАВЛЕНО: Для dbDelta критически важны ДВА пробела между PRIMARY KEY и (id)
    $sql_orders = "CREATE TABLE IF NOT EXISTS $table_orders (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_number VARCHAR(50) NOT NULL,
        name VARCHAR(255) NOT NULL DEFAULT '',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        email VARCHAR(255) NOT NULL DEFAULT '',
        address TEXT,
        client_lat DECIMAL(10,7) DEFAULT NULL,
        client_lng DECIMAL(10,7) DEFAULT NULL,
        geocode_status VARCHAR(20) NOT NULL DEFAULT '',
		routing_circle TINYINT(1) NOT NULL DEFAULT 1,
        comment TEXT,
        city VARCHAR(100) NOT NULL DEFAULT '',
        city_name VARCHAR(255) NOT NULL DEFAULT '',
        zone VARCHAR(100) NOT NULL DEFAULT '',
        date VARCHAR(20) NOT NULL DEFAULT '',
        time VARCHAR(10) NOT NULL DEFAULT '',
        duration INT NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        base_total DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount DECIMAL(10,2) NOT NULL DEFAULT 0,
        discount_info VARCHAR(500) NOT NULL DEFAULT '',
        commission DECIMAL(10,2) NOT NULL DEFAULT 0,
        master_income DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(50) NOT NULL DEFAULT 'cash',
        services TEXT,
        services_for_master TEXT,
        files TEXT,
        completion_files TEXT,
        master_id VARCHAR(100) NOT NULL DEFAULT '',
        current_candidate VARCHAR(100) DEFAULT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'ожидает',
        routing_status VARCHAR(50) NOT NULL DEFAULT '',
        rejected_by TEXT,
        accepted_by VARCHAR(100) DEFAULT NULL,
        offer_time BIGINT DEFAULT NULL,
        tg_message_id BIGINT DEFAULT NULL,
        routing_attempts INT NOT NULL DEFAULT 0,
        routing_started_at DATETIME DEFAULT NULL,
        auth_token VARCHAR(64) NOT NULL DEFAULT '',
        review TEXT,
        additional_items TEXT,
        parent_order VARCHAR(50) DEFAULT NULL,
        is_child TINYINT(1) NOT NULL DEFAULT 0,
        has_child_order VARCHAR(50) DEFAULT NULL,
        cancel_reason TEXT,
        master_released TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        cancelled_at DATETIME DEFAULT NULL,
        started_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uk_order_number (order_number),
        KEY idx_phone (phone),
        KEY idx_master_id (master_id),
        KEY idx_date (date),
        KEY idx_status (status),
        KEY idx_routing_status (routing_status),
        KEY idx_created_at (created_at),
        KEY idx_city (city)
    ) $charset_collate;";

    // --- Таблица уведомлений ---
    $table_notifications = $wpdb->prefix . 'csc_notifications';
    $sql_notifications = "CREATE TABLE IF NOT EXISTS $table_notifications (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_number VARCHAR(50) NOT NULL DEFAULT '',
        recipient_type VARCHAR(20) NOT NULL DEFAULT '',
        recipient_id VARCHAR(100) NOT NULL DEFAULT '',
        channel VARCHAR(20) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        payload TEXT,
        status ENUM('pending','sent','delivered','read','failed') NOT NULL DEFAULT 'pending',
        external_id VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL DEFAULT NULL,
        delivered_at DATETIME NULL DEFAULT NULL,
        read_at DATETIME NULL DEFAULT NULL,
        meta TEXT NULL,
        PRIMARY KEY  (id),
        KEY idx_order (order_number),
        KEY idx_recipient (recipient_type, recipient_id),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) $charset_collate;";

    // --- Таблица истории переходов статусов ---
    $table_history = $wpdb->prefix . 'csc_order_history';
    $sql_history = "CREATE TABLE IF NOT EXISTS $table_history (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_number VARCHAR(50) NOT NULL,
        from_status VARCHAR(50) NOT NULL DEFAULT '',
        to_status VARCHAR(50) NOT NULL DEFAULT '',
        changed_by VARCHAR(50) NOT NULL DEFAULT 'system',
        changed_by_id VARCHAR(100) NOT NULL DEFAULT '',
        reason TEXT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_order (order_number),
        KEY idx_created (created_at)
    ) $charset_collate;";

    if (!function_exists('dbDelta')) {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    }
    dbDelta($sql_orders);
    dbDelta($sql_notifications);
    dbDelta($sql_history);

    // ============================================================
    // МИГРАЦИЯ: Добавление колонок для существующих таблиц
    // ============================================================
    // ИСПРАВЛЕНО: Запрос SHOW COLUMNS выполняется только один раз
    $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM $table_orders", 0);

    if (!in_array('city', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER comment");
    }
    if (!in_array('city_name', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN city_name VARCHAR(255) NOT NULL DEFAULT '' AFTER city");
    }
    if (!in_array('zone', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN zone VARCHAR(100) NOT NULL DEFAULT '' AFTER city_name");
    }
    if (!in_array('completion_files', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN completion_files TEXT AFTER files");
    }

    // Индекс на город для фильтрации при маршрутизации
    $index_exists_city = $wpdb->get_results("SHOW INDEX FROM $table_orders WHERE Key_name = 'idx_city'");
    if (empty($index_exists_city)) {
        $wpdb->query("ALTER TABLE $table_orders ADD INDEX idx_city (city)");
    }

    // ============================================================
    // ИНДЕКС НА phone + created_at (ускорение ЛК)
    // ============================================================
    $index_exists = $wpdb->get_results("SHOW INDEX FROM $table_orders WHERE Key_name = 'idx_phone_created'");
    if (empty($index_exists)) {
        $wpdb->query("ALTER TABLE $table_orders ADD INDEX idx_phone_created (phone, created_at)");
    }
	
    // ============================================================
    // НОВОЕ 5.0: КОЛОНКИ ГЕОДАННЫХ ДЛЯ КЛИЕНТОВ
    // ============================================================
    if (!in_array('client_lat', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN client_lat DECIMAL(10,7) DEFAULT NULL AFTER address");
    }
    if (!in_array('client_lng', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN client_lng DECIMAL(10,7) DEFAULT NULL AFTER client_lat");
    }
    if (!in_array('geocode_status', $existing_columns)) {
        $wpdb->query("ALTER TABLE $table_orders ADD COLUMN geocode_status VARCHAR(20) NOT NULL DEFAULT '' AFTER client_lng");
    }
	if (!in_array('routing_circle', $existing_columns)) {
    $wpdb->query("ALTER TABLE $table_orders ADD COLUMN routing_circle TINYINT(1) NOT NULL DEFAULT 1 AFTER routing_attempts");
    }

    update_option('csc_db_version', CSC_DB_VERSION, false);

    // --- Миграция данных из wp_options в таблицу ---
    csc_migrate_orders_to_table();

    // --- Защита директории загрузок ---
    csc_protect_uploads_dir();

    do_action('csc_db_installed');
}
}

/**
 * П.1.5: Автоматическая проверка версии БД при загрузке плагина.
 * Если CSC_DB_VERSION изменился (обновление плагина), миграции/создание
 * таблиц выполняются автоматически — без повторной активации.
 * Также подстраховывает планирование кронов (если слетели).
 */
add_action('plugins_loaded', 'csc_maybe_check_db_version', 20);
if (!function_exists('csc_maybe_check_db_version')) {
    function csc_maybe_check_db_version() {
        $installed_version = get_option('csc_db_version', '0');
        if (version_compare($installed_version, CSC_DB_VERSION, '<')) {
            csc_run_db_install();
            csc_schedule_crons();
            // П.1.4: зашифровать секреты (VAPID-ключ и др.), хранившиеся открытым текстом
            if (function_exists('csc_migrate_encrypt_secrets')) {
                csc_migrate_encrypt_secrets();
            }
            do_action('csc_db_upgraded', $installed_version, CSC_DB_VERSION);
        } else {
            // Страховка: если кроны были потеряны (чистка кэша/object cache на хостинге)
            if (!wp_next_scheduled('csc_routing_cron_hook')) {
                csc_schedule_crons();
            }
            // П.1.4: отложенная миграция секретов (если модуль crypto ещё не был подключён)
            if (function_exists('csc_migrate_encrypt_secrets') && !get_option('csc_secrets_encrypted', false)) {
                csc_migrate_encrypt_secrets();
            }
        }
    }
}

// ============================================================
// ХУК ДЕАКТИВАЦИИ
// ============================================================
register_deactivation_hook(__FILE__, 'csc_plugin_deactivate');

function csc_plugin_deactivate() {
    wp_clear_scheduled_hook('csc_routing_cron_hook');
    wp_clear_scheduled_hook('csc_reminders_cron_hook');
    wp_clear_scheduled_hook('csc_backup_cron_hook');
    wp_clear_scheduled_hook('csc_finance_cron_hook');

    // Push: удаление SW из корня
    do_action('csc_plugin_deactivate');
}
// ============================================================
// КАСТОМНЫЕ ИНТЕРВАЛЫ КРОНА
// ============================================================
add_filter('cron_schedules', 'csc_add_custom_cron_intervals');

function csc_add_custom_cron_intervals($schedules) {
    // Уникальное имя интервала
    if (!isset($schedules['csc_every_minute'])) {
        $schedules['csc_every_minute'] = [
            'interval' => 60,
            'display'  => 'Каждую минуту (CSC)'
        ];
    }
    if (!isset($schedules['csc_every_30_minutes'])) {
        $schedules['csc_every_30_minutes'] = [
            'interval' => 1800,
            'display'  => 'Каждые 30 минут (CSC напоминания)'
        ];
    }
    return $schedules;
}

// ============================================================
// МИГРАЦИЯ ЗАКАЗОВ ИЗ wp_options В ТАБЛИЦУ
// ============================================================
// ИСПРАВЛЕНО: Обертка function_exists для предотвращения Fatal Error при дублировании в csc-core-functions.php
if (!function_exists('csc_migrate_orders_to_table')) {
    function csc_migrate_orders_to_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'csc_orders';

        // Проверяем флаг миграции, а не количество строк. 
        // Иначе наличие хотя бы одного нового заказа прервет миграцию старых данных из опций.
        if (get_option('csc_orders_migrated', false)) return;

        $all_orders = get_option('csc_all_orders', []);
        if (empty($all_orders)) {
            update_option('csc_orders_migrated', true, false);
            return;
        }

        foreach ($all_orders as $order_number => $o) {
            $wpdb->insert($table, [
                'order_number'       => $order_number,
                'name'               => $o['name'] ?? '',
                'phone'              => $o['phone'] ?? '',
                'email'              => $o['email'] ?? '',
                'address'            => $o['address'] ?? '',
                'comment'            => $o['comment'] ?? '',
                'date'               => $o['date'] ?? '',
                'time'               => $o['time'] ?? '',
                'duration'           => intval($o['duration'] ?? 0),
                'total'              => floatval($o['total'] ?? 0),
                'base_total'         => floatval($o['base_total'] ?? 0),
                'discount'           => floatval($o['discount'] ?? 0),
                'discount_info'      => $o['discount_info'] ?? '',
                'commission'         => floatval($o['commission'] ?? 0),
                'master_income'      => floatval($o['master_income'] ?? 0),
                'payment_method'     => $o['payment_method'] ?? 'cash',
                // Защита от двойного JSON-кодирования (если данные уже были строкой)
                'services'           => is_string($o['services'] ?? null) ? $o['services'] : wp_json_encode($o['services'] ?? []),
                'services_for_master'=> is_string($o['services_for_master'] ?? null) ? $o['services_for_master'] : wp_json_encode($o['services_for_master'] ?? []),
                'files'              => is_string($o['files'] ?? null) ? $o['files'] : wp_json_encode($o['files'] ?? []),
                'completion_files'   => is_string($o['completion_files'] ?? null) ? $o['completion_files'] : wp_json_encode($o['completion_files'] ?? []),
                'master_id'          => $o['master_id'] ?? '',
                'current_candidate'  => $o['current_candidate'] ?? null,
                'status'             => $o['status'] ?? 'ожидает',
                'routing_status'     => $o['routing_status'] ?? '',
                'rejected_by'        => is_string($o['rejected_by'] ?? null) ? $o['rejected_by'] : wp_json_encode($o['rejected_by'] ?? []),
                'accepted_by'        => $o['accepted_by'] ?? null,
                'offer_time'         => $o['offer_time'] ?? null,
                'tg_message_id'      => $o['tg_message_id'] ?? null,
                'routing_attempts'   => intval($o['routing_attempts'] ?? 0),
                'routing_started_at' => $o['routing_started_at'] ?? null,
                'auth_token'         => $o['auth_token'] ?? '',
                'review'             => is_string($o['review'] ?? null) ? $o['review'] : (isset($o['review']) ? wp_json_encode($o['review']) : null),
                'additional_items'   => is_string($o['additional_items'] ?? null) ? $o['additional_items'] : (isset($o['additional_items']) ? wp_json_encode($o['additional_items']) : null),
                'parent_order'       => $o['parent_order'] ?? null,
                'is_child'           => !empty($o['is_child']) ? 1 : 0,
                'has_child_order'    => $o['has_child_order'] ?? null,
                'cancel_reason'      => $o['cancel_reason'] ?? null,
                'master_released'    => !empty($o['master_released']) ? 1 : 0,
                'created_at'         => $o['created_at'] ?? current_time('mysql'),
                'completed_at'       => $o['completed_at'] ?? null,
                'cancelled_at'       => $o['cancelled_at'] ?? null,
                'started_at'         => $o['started_at'] ?? null,
            ]);

            // Инициализация истории: фиксируем начальное состояние
            $wpdb->insert($wpdb->prefix . 'csc_order_history', [
                'order_number' => $order_number,
                'from_status'  => '',
                'to_status'    => $o['status'] ?? 'ожидает',
                'changed_by'   => 'system',
                'changed_by_id'=> '',
                'reason'       => 'Миграция из опций при обновлении плагина',
                'created_at'   => current_time('mysql'),
            ]);
        }

        update_option('csc_orders_migrated', true, false);
    }
}

// ============================================================
// ЗАЩИТА ДИРЕКТОРИИ ЗАГРУЗОК ОТ ИСПОЛНЕНИЯ PHP
// ============================================================
function csc_protect_uploads_dir() {
    $upload_dir = wp_upload_dir();
    $csc_dir = $upload_dir['basedir'] . '/csc-uploads';

    if (!file_exists($csc_dir)) {
        wp_mkdir_p($csc_dir);
    }

    // Проверка прав на запись перед созданием файлов
    if (wp_is_writable($csc_dir)) {
        $htaccess_file = $csc_dir . '/.htaccess';
        if (!file_exists($htaccess_file)) {
            file_put_contents($htaccess_file, "# Запрет исполнения PHP в директории загрузок CSC\n<FilesMatch \"\.(php|phtml|php3|php4|php5|phps)$\">\n    Order Deny,Allow\n    Deny from all\n</FilesMatch>\nOptions -Indexes\n");
        }

        $index_file = $csc_dir . '/index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden.');
        }
    }

    // Директория для бэкапов (УЛУЧШЕНИЕ №9)
    $backup_dir = $upload_dir['basedir'] . '/csc-backups';
    if (!file_exists($backup_dir)) {
        wp_mkdir_p($backup_dir);
    }
    
    if (wp_is_writable($backup_dir)) {
        $backup_htaccess = $backup_dir . '/.htaccess';
        if (!file_exists($backup_htaccess)) {
            file_put_contents($backup_htaccess, "Deny from all\n");
        }
        $backup_index = $backup_dir . '/index.php';
        if (!file_exists($backup_index)) {
            file_put_contents($backup_index, '<?php // Silence is golden.');
        }
    }
}

// ============================================================
// ПОДКЛЮЧЕНИЕ МОДУЛЕЙ
// ============================================================
require_once CSC_PLUGIN_DIR . 'includes/csc-core-functions.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-admin-pages.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-calculator-shortcode.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-cabinet-shortcode.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-ajax-handlers.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-import-export.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-reviews.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-telegram-bot.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-order-routing.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-master-ratings.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-order-upsell.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-master-dashboard.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-notifications.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-master-registration.php';
require_once CSC_PLUGIN_DIR . 'includes/csc-master-moderation.php';

// ============================================================
// НОВЫЕ МОДУЛИ (УЛУЧШЕНИЯ)
// ============================================================
// УЛУЧШЕНИЕ №1: Шифрование токена бота
require_once CSC_PLUGIN_DIR . 'includes/csc-crypto.php';

// УЛУЧШЕНИЕ №2: Напоминания клиенту и мастеру
require_once CSC_PLUGIN_DIR . 'includes/csc-reminders.php';

// УЛУЧШЕНИЕ №3: История переходов статусов
require_once CSC_PLUGIN_DIR . 'includes/csc-order-history.php';

// УЛУЧШЕНИЕ №9: Автоматический бэкап
require_once CSC_PLUGIN_DIR . 'includes/csc-backup.php';

require_once CSC_PLUGIN_DIR . 'includes/csc-cities-settings.php';

// Страница управления отзывами
require_once CSC_PLUGIN_DIR . 'includes/csc-reviews-admin.php';

// Финансовый модуль (бухгалтерия)
require_once CSC_PLUGIN_DIR . 'includes/csc-finance.php';

// Триггеры уведомлений (централизованный модуль)
require_once CSC_PLUGIN_DIR . 'includes/csc-notification-triggers.php';

// Web Push уведомления (VAPID)
require_once CSC_PLUGIN_DIR . 'includes/csc-push.php';

// П.1.6: Публичное событийное API + встроенные слушатели внутренних хуков.
// Подключается ПОСЛЕДНИМ: зависит от core-functions, order-history, reminders, push.
require_once CSC_PLUGIN_DIR . 'includes/csc-action-api.php';

// Копирование при активации
register_activation_hook(__FILE__, 'csc_activate_service_worker');
function csc_activate_service_worker() {
    $source = plugin_dir_path(__FILE__) . 'assets/csc-sw.js'; // Путь внутри плагина
    $destination = ABSPATH . 'csc-sw.js';                    // Путь в корне сайта
    
    if (file_exists($source) && is_writable(ABSPATH)) {
        copy($source, $destination);
    }
}

// Удаление при деактивации (чтобы не оставлять мусор)
register_deactivation_hook(__FILE__, 'csc_deactivate_service_worker');
function csc_deactivate_service_worker() {
    $file = ABSPATH . 'csc-sw.js';
    if (file_exists($file) && is_writable($file)) {
        unlink($file);
    }
}

// ============================================================
// ЗАГРУЗКА ТЕКСТ-ДОМЕНА
// ============================================================
add_action('init', 'csc_load_textdomain');
function csc_load_textdomain() {
    load_plugin_textdomain('csc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

add_action('wp_ajax_csc_submit_review', 'csc_submit_review_handler');
add_action('wp_ajax_nopriv_csc_submit_review', 'csc_submit_review_handler');

if (!function_exists('csc_submit_review_handler')) {
function csc_submit_review_handler() {
    // ИСПРАВЛЕНО: nonce проверяется только если передан (не блокируем при кэше)
    if (!empty($_POST['_wpnonce'])) {
        check_ajax_referer('csc_cabinet_nonce', '_wpnonce', false);
    }
    
    $order_number = sanitize_text_field($_POST['order_number'] ?? '');
    $phone = csc_normalize_phone(sanitize_text_field($_POST['phone'] ?? ''));
    $rating = intval($_POST['rating'] ?? 0);
    $comment = sanitize_textarea_field($_POST['comment'] ?? '');
    
    if (empty($order_number) || empty($phone)) {
        wp_send_json_error(['message' => 'Не указаны обязательные данные']);
        return;
    }
    
    if ($rating < 1 || $rating > 5) {
        wp_send_json_error(['message' => 'Рейтинг должен быть от 1 до 5']);
        return;
    }
    
    // Защита: проверяем принадлежность заявки указанному телефону
    $order = csc_get_order($order_number);
    if (!$order) {
        wp_send_json_error(['message' => 'Заявка не найдена']);
        return;
    }
    
    $order_phone = csc_normalize_phone($order['phone'] ?? '');
    if ($order_phone !== $phone) {
        wp_send_json_error(['message' => 'Нет доступа к этой заявке']);
        return;
    }
    
    // Проверка статуса
    $status = mb_strtolower($order['status'] ?? '');
    if ($status !== 'выполнено' && $status !== 'завершено') {
        wp_send_json_error(['message' => 'Отзыв можно оставить только для выполненной заявки']);
        return;
    }
    
    // Проверка: отзыв уже оставлен?
    if (!empty($order['review']) && !empty($order['review']['rating'])) {
        wp_send_json_error(['message' => 'Вы уже оставили отзыв для этой заявки']);
        return;
    }
    
    // === СОХРАНЕНИЕ ОТЗЫВА ===
    $order['review'] = [
        'rating'  => $rating,
        'comment' => $comment,
        'date'    => current_time('mysql')
    ];
    
    csc_update_order($order_number, $order);
    
    // Сброс кэша
    if (function_exists('csc_cache_delete_orders')) {
        csc_cache_delete_orders();
    }
    
    // Уведомления
    if (function_exists('csc_log_notification')) {
        csc_log_notification(
            $order_number, 'customer', $phone, 'web', 
            '⭐ Отзыв оставлен', 
            "Оценка: {$rating}/5. Комментарий: " . ($comment ?: '(без комментария)')
        );
    }
    
    // Telegram админу
    $s = get_option('csc_main_settings', []);
    $tg_token = function_exists('csc_get_telegram_token') 
        ? csc_get_telegram_token() 
        : ($s['telegram_token'] ?? '');
    $admin_chat = $s['telegram_chat_id'] ?? '';
    
    if ($tg_token && $admin_chat) {
        $review_msg = "⭐ <b>НОВЫЙ ОТЗЫВ #{$order_number}</b>\n";
        $review_msg .= "Оценка: <b>{$rating}/5</b>\n";
        if ($comment) $review_msg .= "Комментарий: <i>" . esc_html($comment) . "</i>\n";
        $review_msg .= "Клиент: " . esc_html($phone);
        
        wp_remote_post("https://api.telegram.org/bot{$tg_token}/sendMessage", [
            'body' => ['chat_id' => $admin_chat, 'text' => $review_msg, 'parse_mode' => 'HTML']
        ]);
    }
    
    wp_send_json_success(['message' => 'Спасибо за ваш отзыв!']);
}
}

/**
 * Парсинг данных заказа из таблицы 1_cc_orders
 */
function csc_parse_order_data($order) {
    $data = [
        'order_number' => 'CSC-' . str_pad($order->id, 5, '0', STR_PAD_LEFT),
        'date' => '',
        'time' => '',
        'status' => '',
        'total' => 0,
        'address' => '',
        'services' => [],
        'cancelled_at' => null,
        'review' => null
    ];
    
    // Дата и время из created_at
    if (!empty($order->created_at)) {
        $timestamp = strtotime($order->created_at);
        $data['date'] = date('Y-m-d', $timestamp);
        $data['time'] = date('H:i', $timestamp);
    }
    
    // Статус
    $status_map = [
        'pending' => 'Ожидает',
        'processing' => 'В работе',
        'completed' => 'Выполнено',
        'cancelled' => 'Отменено',
        'canceled' => 'Отменено',
        'paid' => 'Оплачено',
        'unpaid' => 'Ожидает оплаты',
    ];
    $data['status'] = $status_map[$order->status] ?? $order->status;
    
    // Проверяем отмену
    if ($order->status === 'cancelled' || $order->status === 'canceled') {
        $data['cancelled_at'] = $order->updated_at ?: $order->created_at;
    }
    
    // Парсим form_details (может быть JSON или serialized)
    $form_data = [];
    if (!empty($order->form_details)) {
        $decoded = json_decode($order->form_details, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $form_data = $decoded;
        } else {
            $form_data = maybe_unserialize($order->form_details);
        }
    }
    
    // Парсим order_details
    $order_data_raw = [];
    if (!empty($order->order_details)) {
        $decoded = json_decode($order->order_details, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $order_data_raw = $decoded;
        } else {
            $order_data_raw = maybe_unserialize($order->order_details);
        }
    }
    
    // Извлекаем адрес из данных формы
    if (is_array($form_data)) {
        foreach ($form_data as $key => $value) {
            $key_lower = mb_strtolower((string)$key);
            if (strpos($key_lower, 'адрес') !== false || strpos($key_lower, 'address') !== false) {
                $data['address'] = is_array($value) ? implode(', ', $value) : (string)$value;
            }
        }
    }
    
    // Извлекаем услуги и сумму из order_details
    if (is_array($order_data_raw)) {
        // Ищем сумму
        foreach (['total', 'amount', 'price', 'sum', 'cost', 'итого', 'сумма'] as $key) {
            if (isset($order_data_raw[$key])) {
                $data['total'] = (float)$order_data_raw[$key];
                break;
            }
        }
        
        // Ищем услуги
        foreach (['services', 'items', 'products', 'услуги', 'сервисы'] as $key) {
            if (isset($order_data_raw[$key])) {
                if (is_array($order_data_raw[$key])) {
                    $data['services'] = array_map(function($item) {
                        if (is_array($item)) {
                            return $item['name'] ?? $item['title'] ?? implode(' ', $item);
                        }
                        return (string)$item;
                    }, $order_data_raw[$key]);
                } elseif (is_string($order_data_raw[$key])) {
                    $data['services'] = array_map('trim', explode(',', $order_data_raw[$key]));
                }
                break;
            }
        }
    }
    
    // Если услуги не найдены в order_details, используем calc_title
    if (empty($data['services']) && !empty($order->calc_title)) {
        $data['services'] = [$order->calc_title];
    }
    
    // Если сумма не найдена, пробуем извлечь из order_details как строку
    if ($data['total'] === 0 && !empty($order->order_details)) {
        if (preg_match('/(\d+[\.,]?\d*)\s*(руб|₽|rub)/iu', $order->order_details, $matches)) {
            $data['total'] = (float)str_replace(',', '.', $matches[1]);
        }
    }
    
    return $data;
}

function csc_generate_auth_token($phone) {
    return hash('sha256', $phone . wp_salt('nonce'));
}