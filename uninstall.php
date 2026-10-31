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
 * - Добавлено удаление ВСЕХ опций плагина массовым запросом LIKE 'csc\_%' (гарантия чистоты БД)
 * - Добавлено удаление всех транзиентов плагина, включая токены, кэши, блокировки и лимиты
 *   (включая «осиротевшие» rows таймаутов без парных транзиентов)
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

        // Явный список ключевых настроек (документирует состав данных плагина).
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

        // ГАРАНТИЙНАЯ ОЧИСТКА: все опции с префиксом csc_ одним запросом.
        // Покрывает динамические ключи (csc_push_subs_*, csc_in_cabinet_*,
        // csc_master_notifications_*, csc_order_counter_*, платёжные csc_*),
        // любые будущие опции и явные ключи из списка выше — независимо от
        // регистра/кодировки имени. Подчёркивания экранированы, чтобы LIKE
        // не воспринимал '_' как односимвольный wildcard.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'csc\\_%'");

        // Site-опции мультисайта (wp_sitemeta) с префиксом csc_
        if (is_multisite()) {
            $wpdb->query("DELETE FROM {$wpdb->base_prefix}sitemeta WHERE meta_key LIKE 'csc\\_%'");
        }

        // ============================================================
        // 3. УДАЛЕНИЕ CRON-ЗАДАЧ
        // ============================================================
        // Список соответствует реальным хукам, регистрируемым плагином
        // (см. custom-service-calculator.php csc_plugin_activate/deactivate).
        $csc_cron_hooks = [
            'csc_routing_cron_hook',       // маршрутизация заказов (каждую минуту)
            'csc_reminders_cron_hook',     // напоминания (каждые 30 минут)
            'csc_backup_cron_hook',        // резервные копии (ежедневно)
            'csc_history_cleanup_cron',    // очистка истории заказов (ежедневно)
            'csc_finance_cron_hook',       // финансовый ежедневный cron
            'csc_webhook_cleanup_cron',    // очистка webhook-событий (ежедневно, csc-payment-webhooks.php)
            'csc_tg_webhook_selfcheck_hourly', // самопроверка webhook Telegram (ежечасно, csc-telegram-bot.php)
            'csc_payment_check_cron',      // проверка статусов платежей (каждые 5 минут)
            'csc_payment_stuck_notify_cron', // уведомления о зависших платежах (ежечасно)
        ];

        foreach ($csc_cron_hooks as $csc_cron_hook) {
            wp_clear_scheduled_hook($csc_cron_hook);
        }

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
        // Универсальная очистка: любые транзиенты, чьё имя начинается с csc_
        // (купоны, токены ЛК, geocache, лимиты входа/отзывов, рейтинги, кэши
        // заказов/услуг, webhook-события и все будущие), плюс парные им rows
        // '_transient_timeout_csc_*'. Завершение '%' покрывает и имена вида
        // 'csc_<hash>' (md5/sha токенов), где после префикса нет подчёркивания.
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_csc\\_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_csc\\_%'");

        // Site-транзиенты (актуально для Multisite)
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_csc\\_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_site\\_transient\\_timeout\\_csc\\_%'");

        // «Осиротевшие» rows таймаутов: WordPress хранит срок жизни транзиента
        // в отдельной записи '_transient_timeout_*', которая может пережить сам
        // транзиент (например, удалён через delete_transient до истечения срока).
        // Удаляем оставшиеся timeout-записи без парного транзиента.
        $wpdb->query(
            "DELETE t FROM {$wpdb->options} t
             LEFT JOIN {$wpdb->options} o
               ON o.option_name = CONCAT('_transient_', SUBSTRING(t.option_name, 21))
             WHERE t.option_name LIKE '\\_transient\\_timeout\\_csc\\_%'
               AND o.option_name IS NULL"
        );

        // ============================================================
        // 6. УДАЛЕНИЕ ПОЛЬЗОВАТЕЛЬСКИХ РОЛЕЙ И CAPABILITIES
        // ============================================================
        // Плагин НЕ регистрирует собственные роли и capabilities (проверено
        // по коду: add_role/add_cap в модулях отсутствуют). Блок оставлен как
        // заготовка на будущее — раскомментируйте, если роли появятся:
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