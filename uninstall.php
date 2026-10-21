<?php
/**
 * Файл: uninstall.php
 * Описание: Корректное удаление данных плагина Custom Service Calculator при деинсталляции.
 * Вызывается автоматически при удалении плагина из админ-панели.
 * Версия: 2.0
 *
 * Обновления 2.0:
 * - Добавлено удаление таблицы истории статусов: {prefix}csc_order_history
 * - Добавлено удаление финансовых таблиц платёжной системы:
 *   - {prefix}csc_transactions
 *   - {prefix}csc_financial_ledger
 *   - {prefix}csc_invoices
 *   - {prefix}csc_fiscal_receipts
 * - Добавлено удаление опций городов, модерации, регистрации мастеров, шифрования, миграций
 * - Добавлено удаление динамических опций уведомлений мастеров и платёжных данных
 * - Добавлено удаление всех транзиентов плагина, включая токены, кэши, блокировки и лимиты
 * - Добавлена очистка всех крон-задач плагина, включая будущие финансовые и кассовые
 * - Добавлена поддержка очистки данных в режиме WordPress Multisite
 * - Включено удаление файлов плагина при деинсталляции:
 *   wp-content/uploads/csc-uploads (фото отзывов, файлы мастеров) и wp-content/uploads/csc-backups (бэкапы)
 */

// Защита: файл выполняется только при реальном удалении плагина
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Основная очистка данных плагина для текущего сайта/блога.
 * Вызывается один раз для обычного сайта или для каждого сайта в Multisite.
 */
if (!function_exists('csc_uninstall_cleanup')) {
    function csc_uninstall_cleanup() {
        global $wpdb;

        // ============================================================
        // 1. УДАЛЕНИЕ КАСТОМНЫХ ТАБЛИЦ
        // ============================================================
        $tables_to_drop = [
            $wpdb->prefix . 'csc_orders',
            $wpdb->prefix . 'csc_notifications',
            $wpdb->prefix . 'csc_order_history',
            // Финансовые таблицы (добавлено в 2.0)
            $wpdb->prefix . 'csc_transactions',
            $wpdb->prefix . 'csc_financial_ledger',
            $wpdb->prefix . 'csc_invoices',
            $wpdb->prefix . 'csc_fiscal_receipts',
        ];

        foreach ($tables_to_drop as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        }

        // ============================================================
        // 2. УДАЛЕНИЕ ВСЕХ ОПЦИЙ ПЛАГИНА
        // ============================================================

        // Основные настройки (включая модерацию, города, шифрование, миграции)
        $csc_options = [
            'csc_all_orders',
            'csc_main_settings',
            'csc_service_sections',
            'csc_service_items',
            'csc_masters_list',
            'csc_promo_rules',
            'csc_promo_codes',
            'csc_promo_strategy',
            'csc_promo_disable_global',
            'csc_promo_config',
            'csc_vapid_public_key',
            'csc_vapid_private_key',
            'csc_vapid_subject',
            'csc_notifications_db_version',
            'csc_db_version',
            'csc_orders_migrated',
            'csc_upsell_salt',
            'csc_routing_logs',
            'csc_routing_backup_queue',
            'csc_tg_logs',
            // Города
            'csc_cities',
            'csc_cities_zone_limit_migrated',
            'csc_cities_coords_migrated',
            'csc_zones_sort_order_migrated',
            // Мастера и модерация
            'csc_master_registration_settings',
            'csc_master_agreement_text',
            'csc_masters_service_fields_migrated',
            'csc_moderation_queue',
            'csc_moderation_settings',
            // Шифрование и безопасность
            'csc_token_encrypted',
        ];

        foreach ($csc_options as $option_name) {
            delete_option($option_name);
            // Для Multisite удаляем также сетевые опции, если они были
            if (is_multisite()) {
                delete_site_option($option_name);
            }
        }

        // Удаление опций с динамическими префиксами
        // Счётчики, пуш-подписки, брони, уведомления мастеров
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_order_counter_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_push_subs_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_booked_ranges_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_master_notifications_%'");
        // Динамические платёжные данные (добавлено в 2.0)
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_transactions_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_invoices_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_fiscal_receipts_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_ledger_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_payment_%'");
        // Опции модерации
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc_moderation_%'");

        // ============================================================
        // 3. УДАЛЕНИЕ CRON-ЗАДАЧ
        // ============================================================
        // Базовые хуки
        wp_clear_scheduled_hook('csc_routing_cron_hook');
        wp_clear_scheduled_hook('csc_reminders_cron_hook');
        wp_clear_scheduled_hook('csc_backup_cron_hook');
        wp_clear_scheduled_hook('csc_history_cleanup_cron');

        // Финансовые и кассовые хуки (добавлено в 2.0)
        // ПРОВЕРЬТЕ ИМЕНА ХУКОВ: они должны совпадать с теми, что использовались в плагине
        wp_clear_scheduled_hook('csc_financial_cron_hook');
        wp_clear_scheduled_hook('csc_fiscal_cron_hook');
        wp_clear_scheduled_hook('csc_invoices_cron_hook');
        wp_clear_scheduled_hook('csc_transactions_cron_hook');
        wp_clear_scheduled_hook('csc_ledger_cron_hook');

        // ============================================================
        // 4. ОЧИСТКА ЗАГРУЖЕННЫХ ФАЙЛОВ
        // ============================================================
        // Удаляем все файлы, созданные плагином: загрузки клиентов/мастеров
        // (wp-content/uploads/csc-uploads) и бэкапы (wp-content/uploads/csc-backups).
        $upload_dir = wp_upload_dir();

        $csc_dirs_to_remove = [
            $upload_dir['basedir'] . '/csc-uploads',
            $upload_dir['basedir'] . '/csc-backups',
        ];

        foreach ($csc_dirs_to_remove as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($dir);
        }

        // ============================================================
        // 5. УДАЛЕНИЕ ТРАНЗИЕНТОВ (временные данные)
        // ============================================================
        // Обычные транзиенты
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_csc_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_csc_%'");
        // Site-транзиенты (актуально для Multisite)
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_csc_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_timeout_csc_%'");

        // ============================================================
        // 6. УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЬСКИХ РОЛЕЙ И CAPABILITIES
        // ============================================================
        // Раскомментируйте и адаптируйте, если плагин регистрировал кастомные роли
        /*
        remove_role('csc_master');
        remove_role('csc_manager');
        */

        // ============================================================
        // 7. УДАЛЕНИЕ POST META / USER META (ЕСЛИ ИСПОЛЬЗОВАЛОСЬ)
        // ============================================================
        // Раскомментируйте, если плагин хранил данные в мета-таблицах
        /*
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_csc_%'");
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'csc_%'");
        $wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '_csc_%'");
        */
    }
}

// ============================================================
// ЗАПУСК ОЧИСТКИ (С ПОДДЕРЖКОЙ MULTISITE)
// ============================================================
if (is_multisite()) {
    // Получаем все сайты сети
    $site_ids = get_sites([
        'fields' => 'ids',
        'number' => 0, // Без лимита
    ]);

    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        csc_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    // Обычный (одиночный) сайт
    csc_uninstall_cleanup();
}