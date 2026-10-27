<?php
/**
 * Миграции базы данных плагина CSC.
 *
 * ИСПРАВЛЕНО (продакшн-правка): миграции колонок/индексов выполнялись
 * ТОЛЬКО в хуке активации. При обычном обновлении плагина (замена файлов
 * через wp-admin или WP-CLI без деактивации) новые колонки (reminder_sent,
 * гео-данные, upsell-поля и др.) не создавались, а код уже обращался к ним
 * → SQL-ошибки «Unknown column».
 *
 * Теперь:
 *  - вся схема колонок описана декларативно в csc_db_schema_columns();
 *  - csc_run_db_migrations() идемпотентно досоздаёт отсутствующие колонки/индексы;
 *  - хук admin_init (приоритет 4) сверяет CSC_DB_VERSION с сохранённой опцией
 *    csc_db_version и запускает миграции при ОБНОВЛЕНИИ плагина, а не только
 *    при активации.
 *
 * @package CSC
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('csc_db_schema_columns')) {
    /**
     * Декларативная схема колонок таблицы заказов (порядок = порядок ALTER).
     * Ключи: name — имя колонки; ddl — объявление для ALTER TABLE ADD COLUMN;
     * after — колонка-сосед (или null).
     *
     * @return array[]
     */
    function csc_db_schema_columns() {
        return [
            // --- Базовые поля маршрутизации/городов ---
            ['name' => 'city',             'ddl' => "VARCHAR(100) NOT NULL DEFAULT ''", 'after' => 'comment'],
            ['name' => 'city_name',        'ddl' => "VARCHAR(255) NOT NULL DEFAULT ''", 'after' => 'city'],
            ['name' => 'zone',             'ddl' => "VARCHAR(100) NOT NULL DEFAULT ''", 'after' => 'city_name'],
            ['name' => 'completion_files', 'ddl' => "TEXT",                              'after' => 'files'],

            // --- Гео-данные клиента (карты/маршрутизация) ---
            ['name' => 'client_lat',       'ddl' => "DECIMAL(10,7) DEFAULT NULL",        'after' => 'address'],
            ['name' => 'client_lng',       'ddl' => "DECIMAL(10,7) DEFAULT NULL",        'after' => 'client_lat'],
            ['name' => 'geocode_status',   'ddl' => "VARCHAR(20) NOT NULL DEFAULT ''",   'after' => 'client_lng'],
            ['name' => 'routing_circle',   'ddl' => "TINYINT(1) NOT NULL DEFAULT 1",     'after' => 'routing_attempts'],

            // --- Поля из CREATE, отсутствовавшие в старых ALTER-миграциях ---
            ['name' => 'auth_token',       'ddl' => "VARCHAR(64) NOT NULL DEFAULT ''",   'after' => 'routing_started_at'],
            ['name' => 'review',           'ddl' => "TEXT",                              'after' => 'auth_token'],
            ['name' => 'additional_items', 'ddl' => "TEXT",                              'after' => 'review'],
            ['name' => 'parent_order',     'ddl' => "VARCHAR(50) DEFAULT NULL",          'after' => 'additional_items'],
            ['name' => 'is_child',         'ddl' => "TINYINT(1) NOT NULL DEFAULT 0",     'after' => 'parent_order'],
            ['name' => 'has_child_order',  'ddl' => "VARCHAR(50) DEFAULT NULL",          'after' => 'is_child'],
            ['name' => 'cancel_reason',    'ddl' => "TEXT",                              'after' => 'has_child_order'],
            ['name' => 'master_released',  'ddl' => "TINYINT(1) NOT NULL DEFAULT 0",     'after' => 'cancel_reason'],
            ['name' => 'started_at',       'ddl' => "DATETIME DEFAULT NULL",             'after' => 'cancelled_at'],

            // --- Напоминания (модуль csc-reminders.php) ---
            ['name' => 'reminder_sent',    'ddl' => "TINYINT(1) NOT NULL DEFAULT 0",     'after' => 'completed_at'],
        ];
    }
}

if (!function_exists('csc_db_schema_indexes')) {
    /**
     * Декларативный список индексов таблицы заказов.
     *
     * @return array[] пары [имя индекса, колонки]
     */
    function csc_db_schema_indexes() {
        return [
            ['idx_city',           '(city)'],                  // фильтрация при маршрутизации
            ['idx_phone_created',  '(phone, created_at)'],     // ускорение личного кабинета
            ['idx_reminder_sent',  '(reminder_sent)'],         // выборка заказов для напоминаний
        ];
    }
}

if (!function_exists('csc_run_db_migrations')) {
    /**
     * Идемпотентная миграция схемы: досоздаёт отсутствующие колонки и индексы
     * в таблице csc_orders, затем обновляет версию схемы csc_db_version.
     *
     * Безопасно вызывать многократно (админ-запросы, cron, активация).
     *
     * @param bool $force Принудительно выполнить SHOW COLUMNS даже если версия совпадает.
     * @return void
     */
    function csc_run_db_migrations($force = false) {
        global $wpdb;

        $table = $wpdb->prefix . 'csc_orders';

        // Таблица может ещё не существовать (первая активация до dbDelta) — выходим тихо.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $stored_version = get_option('csc_db_version', '0');
        if (!$force && version_compare($stored_version, CSC_DB_VERSION, '>=')
            && get_option('csc_reminders_column_migrated_v2', false)) {
            return; // схема уже актуальна — не делаем SHOW COLUMNS каждый запрос
        }

        $existing = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($existing)) {
            $existing = [];
        }

        foreach (csc_db_schema_columns() as $col) {
            if (in_array($col['name'], $existing, true)) {
                continue;
            }
            $after_sql = in_array($col['after'], $existing, true)
                ? " AFTER `{$col['after']}`"
                : '';
            $sql = "ALTER TABLE {$table} ADD COLUMN `{$col['name']}` {$col['ddl']}{$after_sql}";
            $res = $wpdb->query($sql);
            if ($res === false) {
                error_log('[CSC_DB_MIGRATION] Ошибка добавления колонки ' . $col['name'] . ': ' . $wpdb->last_error);
            } else {
                error_log('[CSC_DB_MIGRATION] Добавлена колонка ' . $col['name'] . ' в ' . $table);
            }
        }

        foreach (csc_db_schema_indexes() as $idx) {
            list($idx_name, $idx_cols) = $idx;
            $exists = $wpdb->get_results("SHOW INDEX FROM {$table} WHERE Key_name = '{$idx_name}'");
            if (empty($exists)) {
                $res = $wpdb->query("ALTER TABLE {$table} ADD INDEX {$idx_name} {$idx_cols}");
                if ($res === false) {
                    error_log('[CSC_DB_MIGRATION] Ошибка добавления индекса ' . $idx_name . ': ' . $wpdb->last_error);
                }
            }
        }

        // Синхронизируем флаг модуля напоминаний, чтобы csc-reminders.php не делал повторный ALTER.
        $existing_after = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (is_array($existing_after) && in_array('reminder_sent', $existing_after, true)) {
            update_option('csc_reminders_column_migrated_v2', true, false);
        }

        update_option('csc_db_version', CSC_DB_VERSION, false);
    }
}

if (!function_exists('csc_db_migrations_on_update')) {
    /**
     * Хук admin_init: запуск миграций при ОБНОВЛЕНИИ плагина.
     * Срабатывает только при рассогласовании версий (дёшево), либо один раз
     * как «прогрев» после обновления (трансиент-флаг на 5 минут).
     */
    function csc_db_migrations_on_update() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        $stored = get_option('csc_db_version', '0');
        $needs  = version_compare($stored, CSC_DB_VERSION, '<');

        if (!$needs) {
            // Дополнительная страховка: сразу после обновления файлов версии
            // могут совпадать, но трансиент-маркер plugin_version отсутствует.
            $marker = get_transient('csc_db_migration_check');
            if (false !== $marker) {
                return;
            }
            set_transient('csc_db_migration_check', 1, 5 * MINUTE_IN_SECONDS);
            csc_run_db_migrations(true);
            return;
        }

        csc_run_db_migrations(true);
        delete_transient('csc_db_migration_check');
    }
    add_action('admin_init', 'csc_db_migrations_on_update', 4);
}
