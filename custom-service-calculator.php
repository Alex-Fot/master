<?php
/**
 * Plugin Name: Custom Service Calculator
 * Description: Веб-приложение для расчёта и бронирования услуг с плиточным выбором разделов.
 * Version: 9.0
 * Author: Александр Федотов
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain: csc
 * Domain Path: /languages
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
define('CSC_DB_VERSION', '2.3'); // 2.3: индексы idx_master_id / idx_current_candidate / idx_date для SQL-выборок заказов (Кэш заказов); 2.2: перенос миграций БД в admin_init — колонки создаются при ОБНОВЛЕНИИ плагина, а не только при активации

// 3.5: Словарь статусов — константы нужны уже в хуке активации (csc_migrate_statuses_to_latin)
require_once plugin_dir_path(__FILE__) . 'includes/csc-status-i18n.php';

// ============================================================
// ХУК АКТИВАЦИИ
// ============================================================
register_activation_hook(__FILE__, 'csc_plugin_activate');
function csc_plugin_activate() {
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
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
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
        reminder_sent TINYINT(1) NOT NULL DEFAULT 0,
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
    // МИГРАЦИЯ: Добавление колонок/индексов для существующих таблиц
    // ИСПРАВЛЕНО: логика вынесена в csc_run_db_migrations() и вызывается
    // как отсюда (активация), так и из admin_init (при ОБНОВЛЕНИИ плагина).
    // Функция идемпотентна: проверяет наличие колонок/индексов перед ALTER.
    // ============================================================
    csc_run_db_migrations();

    // --- Миграция данных из wp_options в таблицу ---
    csc_migrate_orders_to_table();

    // --- 3.5: UPDATE-миграция кириллических статусов в латинские ключи (одноразово) ---
    csc_migrate_statuses_to_latin();

    // ============================================================
    // РЕГИСТРАЦИЯ КРОН-ЗАДАЧ
    // ============================================================
    // ИСПРАВЛЕНО: Использование уникального префикса 'csc_' для интервалов во избежание конфликтов с другими плагинами
    if (!wp_next_scheduled('csc_routing_cron_hook')) {
        wp_schedule_event(time(), 'csc_every_minute', 'csc_routing_cron_hook');
    }
    if (!wp_next_scheduled('csc_reminders_cron_hook')) {
        wp_schedule_event(time(), 'csc_every_30_minutes', 'csc_reminders_cron_hook');
    }
    if (!wp_next_scheduled('csc_backup_cron_hook')) {
    wp_schedule_event(time(), 'daily', 'csc_backup_cron_hook');
    }
	// ИСПРАВЛЕНО: Регистрация крона очистки истории (была только в admin_init)
    if (!wp_next_scheduled('csc_history_cleanup_cron')) {
    wp_schedule_event(time(), 'daily', 'csc_history_cleanup_cron');
    }
	if (!wp_next_scheduled('csc_finance_cron_hook')) {
    wp_schedule_event(time(), 'daily', 'csc_finance_cron_hook');
    }

    // --- Защита директории загрузок ---
    csc_protect_uploads_dir();
	// Push: установка Service Worker в корень и генерация VAPID
    do_action('csc_plugin_activate');
}

// ============================================================
// ХУК ДЕАКТИВАЦИИ
// ============================================================
register_deactivation_hook(__FILE__, 'csc_plugin_deactivate');

function csc_plugin_deactivate() {
    wp_clear_scheduled_hook('csc_routing_cron_hook');
    wp_clear_scheduled_hook('csc_reminders_cron_hook');
    wp_clear_scheduled_hook('csc_backup_cron_hook');
    wp_clear_scheduled_hook('csc_history_cleanup_cron'); // ИСПРАВЛЕНО: очистка крона истории при деактивации
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
// 3.5: UPDATE-МИГРАЦИЯ СТАТУСОВ ЗАКАЗОВ НА ЛАТИНСКИЕ КЛЮЧИ
// ============================================================
if (!function_exists('csc_migrate_statuses_to_latin')) {
    /**
     * Одноразовый перевод кириллических статусов в латинские ключи
     * в таблицах csc_orders (status) и csc_order_history (from_status/to_status).
     * Флаг: csc_statuses_latin_migrated. Безопасно повторять (UPDATE не трогает латинские значения).
     */
    function csc_migrate_statuses_to_latin() {
        global $wpdb;
        if (get_option('csc_statuses_latin_migrated', false)) return;

        $map = [
            'ожидает'                    => CSC_STATUS_PENDING,
            'поиск мастера'              => CSC_STATUS_SEARCHING,
            'ожидает оплаты'             => CSC_STATUS_AWAITING_PAYMENT,
            'в работе'                   => CSC_STATUS_PROCESSING,
            'выполнено'                  => CSC_STATUS_COMPLETED,
            'завершено'                  => CSC_STATUS_COMPLETED,
            'отменено'                   => CSC_STATUS_CANCELLED,
            'нет мастеров'               => CSC_STATUS_NO_MASTERS,
            'ошибка: время поиска истекло' => CSC_STATUS_ROUTING_TIMEOUT,
        ];

        $tables = [
            $wpdb->prefix . 'csc_orders'        => ['status'],
            $wpdb->prefix . 'csc_order_history' => ['from_status', 'to_status'],
        ];

        foreach ($tables as $table => $columns) {
            // Таблица может ещё не существовать (первая активация) — проверяем наличие
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) continue;
            foreach ($columns as $col) {
                foreach ($map as $old => $new) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$table} SET {$col} = %s WHERE {$col} = %s",
                        $new, $old
                    ));
                }
            }
        }

        // Миграция legacy-статусов из опций (до переноса в таблицу), если они ещё не перенесены
        $all_orders = get_option('csc_all_orders', []);
        if (!empty($all_orders)) {
            $changed = false;
            foreach ($all_orders as $num => &$o) {
                if (!isset($o['status'])) continue;
                $normalized = csc_status_normalize($o['status']);
                if ($normalized !== $o['status']) {
                    $o['status'] = $normalized;
                    $changed = true;
                }
            }
            unset($o);
            if ($changed) update_option('csc_all_orders', $all_orders);
        }

        delete_option('csc_cache_all_orders'); // сброс кэча заказов после миграции (на случай если он option-based)
        if (function_exists('csc_cache_delete_orders')) csc_cache_delete_orders(); // transient-кэш списка заказов
        update_option('csc_statuses_latin_migrated', true, false);
    }
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
                'status'             => $o['status'] ?? CSC_STATUS_PENDING,
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
                'to_status'    => $o['status'] ?? CSC_STATUS_PENDING,
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
require_once CSC_PLUGIN_DIR . 'includes/csc-db-migrations.php';

// Централизованный логгер — подключается раньше всех модулей, использующих csc_log().
require_once CSC_PLUGIN_DIR . 'includes/csc-logger.php';

// 3.5: единый словарь статусов (константы + нормализация) — подключается первым,
// до csc-core-functions.php и остальных модулей, использующих CSC_STATUS_*.
require_once CSC_PLUGIN_DIR . 'includes/csc-status-i18n.php';

// i18n-словари для JS (csc_t(), csc_js_strings_*): подключаются до модулей,
// которые локализуют скрипты (csc-master-dashboard / csc-master-registration).
require_once CSC_PLUGIN_DIR . 'includes/csc-js-i18n.php';

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
add_action('plugins_loaded', 'csc_load_textdomain', 5); // до init: строки нужны уже в cron/ajax/telegram-хуках
function csc_load_textdomain() {
    load_plugin_textdomain('csc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// МЁРТВЫЙ КОД УДАЛЁН (п. 3.6): неиспользуемые функции
// csc_parse_order_data() и csc_generate_auth_token() — ни одного вызова/хука в проекте.
// Дубль обработчика отзывов удалён ранее (п. 3.4); единственный обработчик —
// includes/csc-reviews.php (csc_submit_review_handler_pro).
